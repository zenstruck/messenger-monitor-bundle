<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\History;

use PHPUnit\Framework\TestCase;
use Zenstruck\Messenger\Monitor\History\Snapshot;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;

use function Zenstruck\collect;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class SnapshotTest extends TestCase
{
    /**
     * @test
     */
    public function access_values(): void
    {
        $spec = Specification::fromArray(['from' => '2023-01-01', 'to' => '2023-01-02']);
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('filter')->with($spec)->willReturn(collect(['foo', 'bar']));
        $storage->expects($this->exactly(2))->method('count')->with($this->isInstanceOf(Specification::class))->willReturn(60, 40);
        $storage->expects($this->once())->method('averageWaitTime')->with($spec)->willReturn(2.0);
        $storage->expects($this->once())->method('averageHandlingTime')->with($spec)->willReturn(1.0);

        $snapshot = new Snapshot($storage, $spec);

        $this->assertCount(2, $snapshot->messages());
        $this->assertSame(60, $snapshot->successCount());
        $this->assertSame(40, $snapshot->failureCount());
        $this->assertSame(100, $snapshot->totalCount());
        $this->assertSame(2.0, $snapshot->averageWaitTime());
        $this->assertSame(1.0, $snapshot->averageHandlingTime());
        $this->assertSame(3.0, $snapshot->averageProcessingTime());
        $this->assertSame(0.4, $snapshot->failRate());
        $this->assertSame(0.069, \round($snapshot->handledPerMinute(), 3));
        $this->assertSame(4.167, \round($snapshot->handledPerHour(), 3));
        $this->assertSame(100.000, \round($snapshot->handledPerDay(), 3));
        $this->assertSame(0.035, \round($snapshot->handledPer(30), 3));
    }

    /**
     * @test
     */
    public function cannot_calculate_handled_per_without_from(): void
    {
        $snapshot = new Snapshot($this->createMock(Storage::class), Specification::new());

        $this->expectException(\LogicException::class);

        $snapshot->handledPer(60);
    }

    /**
     * @test
     */
    public function divide_by_zero_fail_rate(): void
    {
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->exactly(2))->method('count')->with($this->isInstanceOf(Specification::class))->willReturn(0, 0);

        $snapshot = new Snapshot($storage, Specification::new());

        $this->assertSame(0.0, $snapshot->failRate());
    }

    /**
     * @test
     */
    public function invalid_wait_times(): void
    {
        $spec = Specification::fromArray(['from' => '2023-01-01', 'to' => '2023-01-02']);
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('averageWaitTime')->with($spec)->willReturn(null);
        $storage->expects($this->once())->method('averageHandlingTime')->with($spec)->willReturn(null);

        $snapshot = new Snapshot($storage, $spec);

        $this->assertSame(0.0, $snapshot->averageWaitTime());
        $this->assertSame(0.0, $snapshot->averageHandlingTime());
        $this->assertSame(0.0, $snapshot->averageProcessingTime());
    }
}
