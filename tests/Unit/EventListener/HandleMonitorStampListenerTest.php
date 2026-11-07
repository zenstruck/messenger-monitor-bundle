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
}
