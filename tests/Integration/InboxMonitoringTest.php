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
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Inbox\InboxInterface;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\InboxMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\MessageIdStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Zenstruck\Messenger\Monitor\EventListener\HandleMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\ReceiveMonitorStampListener;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\ResultNormalizer;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Middleware\InboxMonitoringMiddleware;
use Zenstruck\Messenger\Monitor\Stamp\InboxDeliveryStamp;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageA;

final class InboxMonitoringTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\class_exists(InboxMiddleware::class)) {
            $this->markTestSkipped('Requires Symfony 8.2 inbox support.');
        }
    }

    /**
     * @test
     * @dataProvider duplicateDeliveries
     */
    public function an_inbox_duplicate_is_acknowledged_without_an_extra_history_entry(bool $previouslyHandled, bool $fromFailure): void
    {
        $inbox = $this->createMock(InboxInterface::class);
        $inbox->expects($this->exactly(2))->method('record')->with('async', 'same-message')->willReturnOnConsecutiveCalls(true, false);
        $inbox->expects($this->never())->method('forget');
        $handled = 0;
        $history = [];
        $storage = $this->createMock(Storage::class);
        $storage->method('save')->willReturnCallback(static function(Envelope $envelope) use (&$history): void {
            $history[] = $envelope;
        });
        [$bus, $dispatcher] = $this->createBus($inbox, $storage, static function(MessageA $message) use (&$handled): void {
            ++$handled;
        });
        $transport = new InMemoryTransport(new PhpSerializer());
        $envelope = new Envelope(new MessageA(), [new MonitorStamp(), new MessageIdStamp('same-message')]);

        if ($fromFailure) {
            $envelope = $envelope->with(new SentToFailureTransportStamp('async'));
        }

        $transport->send($envelope);

        if ($previouslyHandled) {
            $envelope = $envelope->with(new HandledStamp(null, 'previous-handler'));
        }

        $transport->send($envelope);
        $worker = new Worker([$fromFailure ? 'failed' : 'async' => $transport], $bus, $dispatcher);
        $worker->run(['stop_when_empty' => true, 'sleep' => 0]);

        $this->assertSame(1, $handled);
        $this->assertCount(2, $transport->getAcknowledged());
        $this->assertCount(1, $history);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function duplicateDeliveries(): iterable
    {
        yield 'ordinary redelivery' => [false, false];
        yield 'redelivery with handler stamps' => [true, false];
        yield 'failure transport replay' => [false, true];
        yield 'failure replay with handler stamps' => [true, true];
    }

    /**
     * @test
     * @dataProvider unhandledDeliveries
     */
    public function preserves_successful_history_when_the_inbox_does_not_skip(bool $configured, bool $hasIdentity, bool $hasHandler, bool $staleDecision): void
    {
        $inbox = $this->createMock(InboxInterface::class);
        $inbox->expects($configured && $hasIdentity ? $this->once() : $this->never())->method('record')->willReturn(true);
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('save');
        [$bus, $dispatcher] = $this->createBus($inbox, $storage, $hasHandler ? static function(MessageA $message): void {} : null, $configured);
        $transport = new InMemoryTransport(new PhpSerializer());
        $envelope = new Envelope(new MessageA(), [new MonitorStamp()]);

        if ($staleDecision) {
            $previous = new InboxDeliveryStamp();
            $previous->markSkipped();
            $envelope = $envelope->with($previous);
        }

        if ($hasIdentity) {
            $envelope = $envelope->with(new MessageIdStamp('new-message'));
        }

        $transport->send($envelope);
        $worker = new Worker(['async' => $transport], $bus, $dispatcher);
        $worker->run(['stop_when_empty' => true, 'sleep' => 0]);

        $this->assertCount(1, $transport->getAcknowledged());
    }

    /** @return iterable<string, array{bool, bool, bool, bool}> */
    public static function unhandledDeliveries(): iterable
    {
        yield 'inbox not configured' => [false, true, true, false];
        yield 'message without an identity' => [true, false, true, false];
        yield 'message without a handler' => [true, true, false, false];
        yield 'stale decision from an earlier delivery' => [true, true, true, true];
    }

    /** @test */
    public function preserves_the_skip_decision_when_a_worker_result_is_serialized(): void
    {
        $inbox = $this->createMock(InboxInterface::class);
        $inbox->expects($this->once())->method('record')->willReturn(false);
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->never())->method('save');
        [$bus, $dispatcher] = $this->createBus($inbox, $storage, null);
        $envelope = $bus->dispatch(new Envelope(new MessageA(), [
            (new MonitorStamp())->markReceived('async'),
            new ReceivedStamp('async'),
            new MessageIdStamp('duplicate'),
            new InboxDeliveryStamp(),
        ]));
        $returned = \unserialize(\serialize($envelope));

        $dispatcher->dispatch(new WorkerMessageHandledEvent($returned, 'async'));

        $this->assertTrue($returned->last(InboxDeliveryStamp::class)->isSkipped());
    }

    /** @test */
    public function preserves_failed_history_when_a_handler_throws(): void
    {
        $inbox = $this->createMock(InboxInterface::class);
        $inbox->expects($this->once())->method('record')->willReturn(true);
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('save')->with(
            $this->isInstanceOf(Envelope::class),
            $this->isInstanceOf(Results::class),
            $this->isInstanceOf(\Throwable::class),
        );
        [$bus, $dispatcher] = $this->createBus($inbox, $storage, static function(MessageA $message): void {
            throw new \RuntimeException('Handler failed.');
        });
        $transport = new InMemoryTransport(new PhpSerializer());
        $transport->send(new Envelope(new MessageA(), [new MonitorStamp(), new MessageIdStamp('new-message')]));
        $worker = new Worker(['async' => $transport], $bus, $dispatcher);
        $worker->run(['stop_when_empty' => true, 'sleep' => 0]);

        $this->assertCount(1, $transport->getRejected());
    }

    /** @return array{MessageBus, EventDispatcher} */
    private function createBus(InboxInterface $inbox, Storage $storage, ?callable $handler, bool $configured = true): array
    {
        $middleware = new InboxMonitoringMiddleware(new InboxMiddleware(new ServiceLocator($configured ? ['async' => static fn() => $inbox] : []), ['failed']));
        $bus = new MessageBus([
            $middleware,
            new HandleMessageMiddleware(new HandlersLocator($handler ? [MessageA::class => [$handler]] : []), true),
        ]);
        $listener = new HandleMonitorStampListener($storage, new ResultNormalizer(__DIR__));
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, new ReceiveMonitorStampListener([]));
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $listener->handleSuccess(...), -100);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $listener->handleFailure(...), -100);

        return [$bus, $dispatcher];
    }
}
