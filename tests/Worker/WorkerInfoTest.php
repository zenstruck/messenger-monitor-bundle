<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Worker;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\WorkerMetadata;
use Zenstruck\Messenger\Monitor\Worker\WorkerInfo;

use function Symfony\Component\Clock\now;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class WorkerInfoTest extends TestCase
{
    use ClockSensitiveTrait;

    /**
     * @test
     */
    public function can_get_time_data(): void
    {
        self::mockTime();

        $info = new WorkerInfo(new WorkerMetadata([]), WorkerInfo::IDLE, now()->getTimestamp(), 0, 0);

        $this->assertSame(0, $info->runningFor());
        $this->assertSame($start = now()->getTimestamp(), $info->startTime()->getTimestamp());

        Clock::get()->sleep(3600);

        $this->assertSame(3600, $info->runningFor());
        $this->assertSame($start, $info->startTime()->getTimestamp());
    }

    /**
     * @test
     */
    public function can_get_status(): void
    {
        $info = new WorkerInfo(new WorkerMetadata([]), WorkerInfo::IDLE, now()->getTimestamp(), 10, 20);

        $this->assertSame(WorkerInfo::IDLE, $info->status());
        $this->assertTrue($info->isIdle());
        $this->assertFalse($info->isProcessing());
        $this->assertSame(10, $info->messagesHandled());
        $this->assertSame(20, $info->memoryUsage()->value());

        $info = new WorkerInfo(new WorkerMetadata([]), WorkerInfo::PROCESSING, now()->getTimestamp(), 10, 20);

        $this->assertSame(WorkerInfo::PROCESSING, $info->status());
        $this->assertFalse($info->isIdle());
        $this->assertTrue($info->isProcessing());
        $this->assertSame(10, $info->messagesHandled());
        $this->assertSame(20, $info->memoryUsage()->value());
    }

    /**
     * @test
     */
    public function can_get_transports(): void
    {
        $info = new WorkerInfo(new WorkerMetadata([]), WorkerInfo::IDLE, now()->getTimestamp(), 0, 0);

        $this->assertEmpty($info->transports());

        $info = new WorkerInfo(new WorkerMetadata(['transportNames' => ['foo', 'bar']]), WorkerInfo::IDLE, now()->getTimestamp(), 0, 0);

        $this->assertSame(['foo', 'bar'], $info->transports());
    }

    /**
     * @test
     */
    public function can_get_queues(): void
    {
        $info = new WorkerInfo(new WorkerMetadata([]), WorkerInfo::IDLE, now()->getTimestamp(), 0, 0);

        $this->assertEmpty($info->queues());

        $info = new WorkerInfo(new WorkerMetadata(['queueNames' => ['foo', 'bar']]), WorkerInfo::IDLE, now()->getTimestamp(), 0, 0);

        $this->assertSame(['foo', 'bar'], $info->queues());
    }
}
