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

use Amp\Parallel\Worker\ContextWorkerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Execution\ParallelExecutionStrategy;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Zenstruck\Messenger\Monitor\EventListener\HandleMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\ReceiveMonitorStampListener;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\ResultNormalizer;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageA;

final class ParallelWorkerMonitoringTest extends TestCase
{
    use ClockSensitiveTrait;

    /**
     * @test
     * @dataProvider handlerOutcomes
     */
    public function records_execution_measurements_from_the_child_process(bool $handlerFails): void
    {
        if (!\class_exists(ParallelExecutionStrategy::class) || !\class_exists(ContextWorkerFactory::class)) {
            $this->markTestSkipped('Requires Symfony 8.2 and amphp/parallel.');
        }

        $clock = self::mockTime('2026-11-13 12:00:00 UTC');
        $startedAt = $clock->now();
        $transport = new InMemoryTransport(new PhpSerializer());
        $transport->send(new Envelope(new MessageA(throw: $handlerFails), [new MonitorStamp()]));
        $history = [];
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('save')->willReturnCallback(static function(Envelope $envelope, Results $results, ?\Throwable $error = null) use (&$history): void {
            $history[] = [$envelope, $error];
        });
        $listener = new HandleMonitorStampListener($storage, new ResultNormalizer(__DIR__));
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, new ReceiveMonitorStampListener([]));
        $dispatcher->addListener(WorkerMessageHandledEvent::class, static fn() => $clock->sleep(20), 100);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, static fn() => $clock->sleep(20), 100);
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $listener->handleSuccess(...), -100);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $listener->handleFailure(...), -100);
        $strategy = new ParallelExecutionStrategy(__DIR__.'/../Fixture/parallel-worker.php', 2);
        $worker = new Worker(['async' => $transport], new MessageBus(), $dispatcher, messageExecutionStrategy: $strategy);
        $worker->run(['stop_when_empty' => true, 'sleep' => 0]);

        $this->assertCount(1, $history);
        [$envelope, $error] = $history[0];
        $stamp = $envelope->last(MonitorStamp::class);
        $result = $envelope->last(HandledStamp::class)?->getResult();

        if ($handlerFails) {
            $this->assertInstanceOf(HandlerFailedException::class, $error);
            $exceptions = $error->getWrappedExceptions();
            $result = \json_decode(\reset($exceptions)->getMessage(), true, flags: \JSON_THROW_ON_ERROR);
        }

        if (!\extension_loaded('parallel')) {
            $this->assertNotSame(\getmypid(), $result['process']);
        }

        $this->assertGreaterThanOrEqual($result['memory'], $stamp->memoryUsage());
        $this->assertEquals($startedAt->modify('+3 seconds'), $stamp->finishedAt());
    }

    /** @return iterable<string, array{bool}> */
    public static function handlerOutcomes(): iterable
    {
        yield 'successful handler' => [false];
        yield 'failed handler' => [true];
    }
}
