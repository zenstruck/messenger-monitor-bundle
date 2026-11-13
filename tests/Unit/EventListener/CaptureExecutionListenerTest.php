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
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Zenstruck\Messenger\Monitor\EventListener\CaptureExecutionListener;
use Zenstruck\Messenger\Monitor\Stamp\ExecutionStamp;
use Zenstruck\Messenger\Monitor\Stamp\HandlerMetricsStamp;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;

final class CaptureExecutionListenerTest extends TestCase
{
    /** @test */
    public function overlapping_deliveries_keep_their_measurements_separate_and_serializable(): void
    {
        $listener = new CaptureExecutionListener();
        $descriptor = new HandlerDescriptor(static fn() => null);
        $first = new Envelope(new \stdClass(), [new HandlerMetricsStamp()]);
        $second = new Envelope(new \stdClass(), [new HandlerMetricsStamp()]);
        $firstEvent = (object) ['envelope' => $first, 'handlerDescriptor' => $descriptor];
        $secondEvent = (object) ['envelope' => $second, 'handlerDescriptor' => $descriptor];
        $name = $descriptor->getName();

        $listener->onStart($firstEvent);
        $listener->onStart($secondEvent);
        $listener($secondEvent);

        $this->assertSame([], $first->last(HandlerMetricsStamp::class)->forHandler($name));
        $completed = $second->last(HandlerMetricsStamp::class)->forHandler($name);
        $listener($firstEvent);
        $listener($secondEvent);
        $this->assertSame($completed, $second->last(HandlerMetricsStamp::class)->forHandler($name));

        $restored = \unserialize(\serialize($first));
        $metrics = $restored->last(HandlerMetricsStamp::class)->forHandler($name);
        $this->assertGreaterThanOrEqual(0, $metrics['duration_ms']);
        $this->assertIsInt($metrics['memory_change']);
        $this->assertSame([], $restored->last(HandlerMetricsStamp::class)->forHandler('not-called'));
    }

    /** @test */
    public function omits_batch_measurements_without_losing_message_execution_measurements(): void
    {
        $handler = new class implements BatchHandlerInterface {
            public function __invoke(\stdClass $message, ?Acknowledger $acknowledger = null): int
            {
                return 0;
            }

            public function flush(bool $force): void
            {
            }
        };
        $descriptor = new HandlerDescriptor($handler);
        $envelope = new Envelope(new \stdClass(), [new HandlerMetricsStamp(), new ExecutionStamp()]);
        $event = (object) ['envelope' => $envelope, 'handlerDescriptor' => $descriptor];
        $listener = new CaptureExecutionListener();

        $listener->onStart($event);
        $listener($event);

        $this->assertSame([], $envelope->last(HandlerMetricsStamp::class)->forHandler($descriptor->getName()));
        $monitor = (new MonitorStamp())->markReceived('async');
        $finished = $envelope->last(ExecutionStamp::class)->finishMonitor($monitor);
        $this->assertTrue($finished->isFinished());
    }
}
