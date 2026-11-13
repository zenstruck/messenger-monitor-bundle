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
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Handler\RedispatchMessageHandler;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\RedispatchStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Generator\MessageGeneratorInterface;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;
use Zenstruck\Messenger\Monitor\EventListener\AddMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\HandleMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\ReceiveMonitorStampListener;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\ResultNormalizer;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Middleware\SchedulerMonitoringMiddleware;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageA;

final class SchedulerMonitoringTest extends TestCase
{
    /**
     * @test
     * @dataProvider handlerOutcomes
     */
    public function forwarding_does_not_create_an_extra_history_entry(string $mode, bool $autoStamp, bool $handlerFails, bool $previouslyHandled = false, bool $serialized = true): void
    {
        $scheduler = $this->scheduler($mode, $previouslyHandled ? [new HandledStamp('previous', 'previous-handler')] : []);
        $target = new InMemoryTransport($serialized ? new PhpSerializer() : null);
        $history = [];
        $storage = $this->storage($history);
        $handled = 0;
        [$bus, $dispatcher] = $this->createBus($target, $storage, static function(MessageA $message) use (&$handled, $handlerFails): string {
            ++$handled;

            if ($handlerFails) {
                throw new \RuntimeException('Handler failed.');
            }

            return 'done';
        }, $autoStamp);
        $worker = new Worker(['scheduler_default' => $scheduler], $bus, $dispatcher);
        $worker->run(['sleep' => 0]);

        $this->assertCount(0, $history);
        $this->assertSame(0, $handled);
        $this->assertCount(1, $target->getSent());

        $consumer = new Worker(['async' => $target], $bus, $dispatcher);
        $consumer->run(['sleep' => 0]);

        $this->assertSame(1, $handled);
        $this->assertCount(1, $history);
        [$envelope, $error] = $history[0];
        $this->assertInstanceOf(MessageA::class, $envelope->getMessage());
        $this->assertSame('async', $envelope->last(MonitorStamp::class)->transport());
        $this->assertTrue($envelope->last(MonitorStamp::class)->isFinished());
        $this->assertSame('schedule:default:task', $envelope->last(TagStamp::class)->value);
        $this->assertSame($handlerFails, null !== $error);
        $this->assertCount($handlerFails ? 1 : 0, $target->getRejected());
        $this->assertCount($handlerFails ? 0 : 1, $target->getAcknowledged());
    }

    /** @return iterable<string, array{string, bool, bool, bool?, bool?}> */
    public static function handlerOutcomes(): iterable
    {
        foreach (self::forwardingModes() as $name => [$mode, $autoStamp]) {
            yield $name.', successful handler' => [$mode, $autoStamp, false];
            yield $name.', failed handler' => [$mode, $autoStamp, true];
        }

        yield 'redispatch with a previous handler stamp' => ['redispatch', false, false, true];
        yield 'routing with a previous handler stamp' => ['routing', false, false, true];
        yield 'routing without transport serialization' => ['routing', false, false, false, false];
    }

    /**
     * @test
     * @dataProvider forwardingModes
     */
    public function forwarding_failures_are_still_recorded(string $mode, bool $autoStamp): void
    {
        $scheduler = $this->scheduler($mode);
        $target = $this->createMock(SenderInterface::class);
        $target->expects($this->once())->method('send')->willThrowException(new \RuntimeException('Broker unavailable.'));
        $history = [];
        $storage = $this->storage($history);
        [$bus, $dispatcher] = $this->createBus($target, $storage, function(MessageA $message): void {
            $this->fail('Forwarding must not run the handler.');
        }, $autoStamp);
        $worker = new Worker(['scheduler_default' => $scheduler], $bus, $dispatcher);
        $worker->run(['sleep' => 0]);

        $this->assertCount(1, $history);
        [$envelope, $error] = $history[0];
        $this->assertSame('scheduler_default', $envelope->last(MonitorStamp::class)->transport());
        $this->assertTrue($envelope->last(MonitorStamp::class)->isFinished());
        $this->assertNotNull($error);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function forwardingModes(): iterable
    {
        yield 'redispatch, automatic stamp' => ['redispatch', true];
        yield 'redispatch, automatic stamp disabled' => ['redispatch', false];
        yield 'routing, automatic stamp' => ['routing', true];
        yield 'routing, automatic stamp disabled' => ['routing', false];
    }

    /**
     * @test
     * @dataProvider localDeliveries
     */
    public function locally_consumed_tasks_are_still_recorded(string $mode, bool $hasHandler, bool $previouslySent = false): void
    {
        $scheduler = $this->scheduler($mode, $previouslySent ? [new SentStamp('previous-sender', 'previous-transport')] : []);
        $target = $this->createMock(SenderInterface::class);
        $target->expects($this->never())->method('send');
        $history = [];
        $storage = $this->storage($history);
        $handled = 0;
        $handler = static function(MessageA $message) use (&$handled): void { ++$handled; };
        [$bus, $dispatcher] = $this->createBus($target, $storage, $hasHandler ? $handler : null, false, false);
        $worker = new Worker(['scheduler_default' => $scheduler], $bus, $dispatcher);
        $worker->run(['sleep' => 0]);

        $this->assertSame($hasHandler ? 1 : 0, $handled);
        $this->assertCount(1, $history);
        [$envelope, $error] = $history[0];
        $this->assertInstanceOf(MessageA::class, $envelope->getMessage());
        $this->assertSame('scheduler_default', $envelope->last(MonitorStamp::class)->transport());
        $this->assertNull($error);
    }

    /** @return iterable<string, array{string, bool, bool?}> */
    public static function localDeliveries(): iterable
    {
        yield 'direct handling' => ['direct', true];
        yield 'direct, no handler' => ['direct', false];
        yield 'routing without a sender' => ['routing', true];
        yield 'routing without a sender or handler' => ['routing', false];
        yield 'direct, no handler, with a previous sent stamp' => ['direct', false, true];
    }

    /**
     * @test
     * @dataProvider inlineDeliveries
     */
    public function handling_inline_by_a_sender_is_still_recorded(string $mode): void
    {
        $scheduler = $this->scheduler($mode);
        $handled = 0;
        $handler = static function(MessageA $message) use (&$handled): void { ++$handled; };
        $handlingBus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([MessageA::class => [$handler]]))]);
        $history = [];
        $storage = $this->storage($history);
        [$bus, $dispatcher] = $this->createBus(new SyncTransport($handlingBus), $storage, null, false);
        $worker = new Worker(['scheduler_default' => $scheduler], $bus, $dispatcher);
        $worker->run(['sleep' => 0]);

        $this->assertSame(1, $handled);
        $this->assertCount(1, $history);
        $this->assertNull($history[0][1]);
    }

    /** @return iterable<string, array{string}> */
    public static function inlineDeliveries(): iterable
    {
        yield 'redispatch' => ['redispatch'];
        yield 'routing' => ['routing'];
    }

    private function scheduler(string $mode, array $stamps = []): SchedulerTransport
    {
        if ('routing' === $mode && !\class_exists(RedispatchStamp::class)) {
            $this->markTestSkipped('Requires Symfony 8.2 Scheduler routing.');
        }

        $message = new Envelope(new MessageA(), $stamps);
        $messages = ['redispatch' === $mode ? new RedispatchMessage($message, 'async') : $message];
        $context = new MessageContext('default', 'task', new PeriodicalTrigger('1 hour'), new \DateTimeImmutable());
        $generator = $this->createMock(MessageGeneratorInterface::class);
        $generator->method('getMessages')->willReturnCallback(static function() use (&$messages, $context): iterable {
            foreach ($messages as $message) {
                yield $context => $message;
            }

            $messages = [];
        });

        if ('routing' === $mode) {
            return new SchedulerTransport($generator, true);
        }

        return new SchedulerTransport($generator);
    }

    private function storage(array &$history): Storage
    {
        $storage = $this->createMock(Storage::class);
        $storage->method('save')->willReturnCallback(static function(Envelope $envelope, Results $results, ?\Throwable $error = null) use (&$history): void {
            $history[] = [$envelope, $error];
        });

        return $storage;
    }

    /** @return array{MessageBus, EventDispatcher} */
    private function createBus(SenderInterface $target, Storage $storage, ?callable $handler, bool $autoStamp, bool $hasSender = true): array
    {
        $dispatcher = new EventDispatcher();
        $listener = new HandleMonitorStampListener($storage, new ResultNormalizer(__DIR__));

        if ($autoStamp) {
            $dispatcher->addListener(SendMessageToTransportsEvent::class, new AddMonitorStampListener());
        }

        $dispatcher->addListener(WorkerMessageReceivedEvent::class, new ReceiveMonitorStampListener([]));
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $listener->handleSuccess(...), -100);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $listener->handleFailure(...), -100);
        $dispatcher->addListener(WorkerRunningEvent::class, static function(WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        });
        $senders = new SendersLocator($hasSender ? [MessageA::class => ['async']] : [], new ServiceLocator(['async' => static fn() => $target]));
        $handlers = [RedispatchMessage::class => [static function(RedispatchMessage $message) use (&$bus): mixed {
            return (new RedispatchMessageHandler($bus))($message);
        }]];

        if ($handler) {
            $handlers[MessageA::class] = [$handler];
        }

        $bus = new MessageBus([
            new SchedulerMonitoringMiddleware(),
            new SendMessageMiddleware($senders, $dispatcher),
            new HandleMessageMiddleware(new HandlersLocator($handlers), true),
        ]);

        return [$bus, $dispatcher];
    }
}
