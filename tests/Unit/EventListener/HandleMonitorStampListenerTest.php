<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Messenger\Monitor\EventListener\HandleMonitorStampListener;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\ResultNormalizer;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class HandleMonitorStampListenerTest extends TestCase
{
    /**
     * @test
     */
    public function handles_success(): void
    {
        $envelope = new Envelope(new \stdClass(), [
            (new MonitorStamp())->markReceived('foo'),
            new HandledStamp('handler', 'return'),
        ]);
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('save')->with(
            $this->isInstanceOf(Envelope::class),
            $this->isInstanceOf(Results::class),
        );

        $listener = new HandleMonitorStampListener($storage, new ResultNormalizer(__DIR__));

        $listener->handleSuccess($event = new WorkerMessageHandledEvent($envelope, 'foo'));

        $this->assertTrue($event->getEnvelope()->last(MonitorStamp::class)->isFinished());
    }

    /**
     * @test
     */
    public function handles_success_invalid(): void
    {
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->never())->method('save');

        $listener = new HandleMonitorStampListener($storage, new ResultNormalizer(__DIR__));

        $listener->handleSuccess(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'foo'));
        $listener->handleSuccess(new WorkerMessageHandledEvent(new Envelope(new \stdClass(), [new MonitorStamp()]), 'foo'));
    }

    /**
     * @test
     */
    public function handles_failure(): void
    {
        $envelope = new Envelope(new \stdClass(), [(new MonitorStamp())->markReceived('foo')]);
        $exception = new \RuntimeException();
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('save')->with(
            $this->isInstanceOf(Envelope::class),
            $this->isInstanceOf(Results::class),
            $exception,
        );

        $listener = new HandleMonitorStampListener($storage, new ResultNormalizer(__DIR__));

        $listener->handleFailure($event = new WorkerMessageFailedEvent($envelope, 'foo', $exception));

        $this->assertTrue($event->getEnvelope()->last(MonitorStamp::class)->isFinished());
    }

    /**
     * @test
     */
    public function handles_failure_invalid(): void
    {
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->never())->method('save');

        $listener = new HandleMonitorStampListener($storage, new ResultNormalizer(__DIR__));

        $listener->handleFailure(new WorkerMessageFailedEvent(new Envelope(new \stdClass()), 'foo', new \RuntimeException()));
        $listener->handleFailure(new WorkerMessageFailedEvent(new Envelope(new \stdClass(), [new MonitorStamp()]), 'foo', new \RuntimeException()));
    }

    /**
     * @test
     * @dataProvider handlerOutcomes
     */
    public function logs_history_storage_errors(bool $handlerFails): void
    {
        $envelope = new Envelope(new \stdClass(), [(new MonitorStamp())->markReceived('foo')]);
        $handlerError = $handlerFails ? new \RuntimeException('Handler failed.') : null;
        $storageError = new \RuntimeException('History database unavailable.');
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('save')->with(
            $this->isInstanceOf(Envelope::class),
            $this->isInstanceOf(Results::class),
            $handlerError,
        )->willThrowException($storageError);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'Failed to save Messenger Monitor history for message "{class}".',
            ['class' => \stdClass::class, 'exception' => $storageError],
        );
        $listener = new HandleMonitorStampListener($storage, new ResultNormalizer(__DIR__), $logger);

        if ($handlerError) {
            $listener->handleFailure(new WorkerMessageFailedEvent($envelope, 'foo', $handlerError));

            return;
        }

        $listener->handleSuccess(new WorkerMessageHandledEvent($envelope, 'foo'));
    }

    /** @return iterable<string, array{bool}> */
    public static function handlerOutcomes(): iterable
    {
        yield 'successful handler' => [false];
        yield 'failed handler' => [true];
    }

    /** @test */
    public function logs_result_normalization_errors(): void
    {
        $normalizationError = new \Error('Result cannot be converted to a string.');
        $result = new class($normalizationError) implements \Stringable {
            public function __construct(private \Error $error)
            {
            }

            public function __toString(): string
            {
                throw $this->error;
            }
        };
        $envelope = new Envelope(new \stdClass(), [
            (new MonitorStamp())->markReceived('foo'),
            new HandledStamp($result, 'handler'),
        ]);
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->never())->method('save');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'Failed to save Messenger Monitor history for message "{class}".',
            ['class' => \stdClass::class, 'exception' => $normalizationError],
        );
        $listener = new HandleMonitorStampListener($storage, new ResultNormalizer(__DIR__), $logger);

        $listener->handleSuccess(new WorkerMessageHandledEvent($envelope, 'foo'));
    }
}
