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
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\HandlerFailureEvent;
use Symfony\Component\Messenger\Event\HandlerStartingEvent;
use Symfony\Component\Messenger\Event\HandlerSuccessEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Zenstruck\Messenger\Monitor\EventListener\CaptureExecutionListener;
use Zenstruck\Messenger\Monitor\EventListener\HandleMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\ReceiveMonitorStampListener;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\ResultNormalizer;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;

final class HandlerMetricsTest extends TestCase
{
    /**
     * @test
     * @dataProvider handlerOutcomes
     */
    public function records_each_handlers_duration_and_memory_change_in_one_history_entry(bool $failure): void
    {
        if (!\class_exists(HandlerStartingEvent::class)) {
            $this->markTestSkipped('Requires Symfony 8.2 handler events.');
        }

        $buffer = null;
        $handlers = [
            new HandlerDescriptor(static function() use (&$buffer) {
                $buffer = \str_repeat('x', 8 * 1024 * 1024);
                \usleep(3000);

                return 'allocated';
            }, ['alias' => 'allocate']),
            new HandlerDescriptor(static function() use (&$buffer) {
                $buffer = null;
                \usleep(3000);

                return 'released';
            }, ['alias' => 'release']),
            new HandlerDescriptor(static function() use ($failure) {
                \usleep(3000);

                if ($failure) {
                    throw new \RuntimeException('Third handler failed.');
                }

                return 'finished';
            }, ['alias' => 'third']),
        ];
        $dispatcher = new EventDispatcher();
        $capture = new CaptureExecutionListener();
        $dispatcher->addListener(HandlerStartingEvent::class, $capture->onStart(...));
        $dispatcher->addListener(HandlerSuccessEvent::class, $capture);
        $dispatcher->addListener(HandlerFailureEvent::class, $capture);
        $history = [];
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('save')->willReturnCallback(static function(Envelope $envelope, Results $results, ?\Throwable $error = null) use (&$history): void {
            $history[] = $results;
        });
        $listener = new HandleMonitorStampListener($storage, new ResultNormalizer(__DIR__));
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, new ReceiveMonitorStampListener([]));
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $listener->handleSuccess(...));
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $listener->handleFailure(...));
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([\stdClass::class => $handlers]), dispatcher: $dispatcher)]);
        $transport = new InMemoryTransport(new PhpSerializer());
        $transport->send(new Envelope(new \stdClass(), [new MonitorStamp()]));
        $worker = new Worker(['async' => $transport], $bus, $dispatcher);
        $worker->run(['stop_when_empty' => true, 'sleep' => 0]);

        $this->assertCount(1, $history);
        $results = $history[0]->all();
        $this->assertCount(3, $results);
        $this->assertSame(['allocated', 'released'], [$results[0]->data()['data'], $results[1]->data()['data']]);
        $this->assertSame($failure, $results[2]->isFailure());

        foreach ($results as $result) {
            $this->assertGreaterThanOrEqual(2, $result->duration());
            $this->assertIsInt($result->memoryChange());
        }

        $this->assertGreaterThanOrEqual(7 * 1024 * 1024, $results[0]->memoryChange());
        $this->assertLessThanOrEqual(-7 * 1024 * 1024, $results[1]->memoryChange());
    }

    /** @return iterable<string, array{bool}> */
    public static function handlerOutcomes(): iterable
    {
        yield 'successful handlers' => [false];
        yield 'failed handler' => [true];
    }
}
