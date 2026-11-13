<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\OutboxSender;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Zenstruck\Messenger\Monitor\EventListener\AddMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\HandleMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\ReceiveMonitorStampListener;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\ResultNormalizer;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageA;

final class OutboxMonitoringTest extends TestCase
{
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        if (!\class_exists(OutboxSender::class)) {
            $this->markTestSkipped('Requires Symfony 8.2 outbox support.');
        }
    }

    /**
     * @test
     * @dataProvider handlerOutcomes
     */
    public function preserves_monitoring_from_dispatch_through_relay_to_handling(bool $handlerFails, bool $autoStamp): void
    {
        $clock = self::mockTime('2026-11-13 12:00:00');
        $dispatchedAt = $clock->now();
        $outbox = new InMemoryTransport(new PhpSerializer());
        $target = new InMemoryTransport(new PhpSerializer());
        $history = [];
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('save')->willReturnCallback(static function(Envelope $envelope, Results $results, ?\Throwable $error = null) use (&$history): void {
            $history[] = [$envelope, $error];
        });
        $handled = 0;
        [$bus, $dispatcher] = $this->createBus($outbox, $target, $storage, static function(MessageA $message) use ($clock, $handlerFails, &$handled): void {
            ++$handled;
            $clock->sleep(3);

            if ($handlerFails) {
                throw new \RuntimeException('Handler failed.');
            }
        }, $autoStamp);
        $previous = new MonitorStamp($autoStamp ? new \DateTimeImmutable('2000-01-01') : $dispatchedAt);
        $dispatched = $bus->dispatch(new MessageA(), [$previous]);
        $original = $dispatched->last(MonitorStamp::class);
        $this->assertSame($autoStamp, $previous->runId() !== $original->runId());
        $this->assertEquals($dispatchedAt, $original->dispatchedAt());

        $clock->sleep(5);
        $relay = new Worker(['outbox' => $outbox], $bus, $dispatcher);
        $relay->run(['stop_when_empty' => true, 'sleep' => 0]);

        $this->assertSame([], $history);
        $this->assertSame(0, $handled);
        $this->assertCount(1, $target->getSent());
        $forwarded = $target->getSent()[0]->last(MonitorStamp::class);
        $this->assertSame($original->runId(), $forwarded->runId());
        $this->assertEquals($dispatchedAt, $forwarded->dispatchedAt());

        $clock->sleep(7);
        $consumer = new Worker(['async' => $target], $bus, $dispatcher);
        $consumer->run(['stop_when_empty' => true, 'sleep' => 0]);

        $this->assertSame(1, $handled);
        $this->assertCount(1, $history);
        [$envelope, $error] = $history[0];
        $finished = $envelope->last(MonitorStamp::class);
        $this->assertSame($original->runId(), $finished->runId());
        $this->assertEquals($dispatchedAt, $finished->dispatchedAt());
        $this->assertEquals($dispatchedAt->modify('+12 seconds'), $finished->receivedAt());
        $this->assertEquals($dispatchedAt->modify('+15 seconds'), $finished->finishedAt());
        $this->assertSame('async', $finished->transport());
        $this->assertSame($handlerFails, null !== $error);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function handlerOutcomes(): iterable
    {
        yield 'successful handler, automatic stamp' => [false, true];
        yield 'failed handler, automatic stamp' => [true, true];
        yield 'successful handler, explicit stamp' => [false, false];
        yield 'failed handler, explicit stamp' => [true, false];
    }

    /**
     * @test
     * @dataProvider stampModes
     */
    public function records_a_relay_failure_on_the_outbox_transport(bool $autoStamp): void
    {
        $clock = self::mockTime('2026-11-13 12:00:00');
        $dispatchedAt = $clock->now();
        $outbox = new InMemoryTransport(new PhpSerializer());
        $failure = new \RuntimeException('Broker unavailable.');
        $target = $this->createMock(SenderInterface::class);
        $target->expects($this->once())->method('send')->willThrowException($failure);
        $history = [];
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('save')->willReturnCallback(static function(Envelope $envelope, Results $results, ?\Throwable $error = null) use (&$history): void {
            $history[] = [$envelope, $error];
        });
        [$bus, $dispatcher] = $this->createBus($outbox, $target, $storage, function(MessageA $message): void {
            $this->fail('The relay must not call the handler.');
        }, $autoStamp);
        $dispatched = $bus->dispatch(new MessageA(), [new MonitorStamp()]);
        $original = $dispatched->last(MonitorStamp::class);

        $clock->sleep(5);
        $relay = new Worker(['outbox' => $outbox], $bus, $dispatcher);
        $relay->run(['stop_when_empty' => true, 'sleep' => 0]);

        $this->assertCount(1, $history);
        [$envelope, $error] = $history[0];
        $stamp = $envelope->last(MonitorStamp::class);
        $this->assertSame($failure, $error);
        $this->assertSame($original->runId(), $stamp->runId());
        $this->assertEquals($dispatchedAt, $stamp->dispatchedAt());
        $this->assertSame('outbox', $stamp->transport());
        $this->assertTrue($stamp->isFinished());
    }

    /** @return iterable<string, array{bool}> */
    public static function stampModes(): iterable
    {
        yield 'automatic stamp' => [true];
        yield 'explicit stamp' => [false];
    }

    /** @return array{MessageBus, EventDispatcher} */
    private function createBus(InMemoryTransport $outbox, SenderInterface $target, Storage $storage, callable $handler, bool $autoStamp): array
    {
        $dispatcher = new EventDispatcher();
        $listener = new HandleMonitorStampListener($storage, new ResultNormalizer(__DIR__));

        if ($autoStamp) {
            $dispatcher->addListener(SendMessageToTransportsEvent::class, new AddMonitorStampListener());
        }

        $dispatcher->addListener(WorkerMessageReceivedEvent::class, new ReceiveMonitorStampListener([]));
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $listener->handleSuccess(...), -100);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $listener->handleFailure(...), -100);
        $sender = new OutboxSender($target, $outbox, 'async', 'outbox');
        $senders = new SendersLocator([MessageA::class => ['async']], new ServiceLocator(['async' => static fn() => $sender]));
        $bus = new MessageBus([
            new SendMessageMiddleware($senders, $dispatcher),
            new HandleMessageMiddleware(new HandlersLocator([MessageA::class => [$handler]])),
        ]);

        return [$bus, $dispatcher];
    }
}
