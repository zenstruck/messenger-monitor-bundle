<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\WorkerMetadata;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Worker\WorkerInfo;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class WorkerCacheTest extends TestCase
{
    use ClockSensitiveTrait;

    private ArrayAdapter $cache;
    private WorkerCache $workerCache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->workerCache = new WorkerCache($this->cache, 3600);
    }

    /**
     * @test
     */
    public function a_stopped_worker_stays_removed_after_a_stale_index_write(): void
    {
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);

        $this->workerCache->add(123, $metadata, 0, 1000);

        $workers = \iterator_to_array($this->workerCache);
        $this->assertCount(1, $workers);
        $this->assertSame(WorkerInfo::IDLE, $workers[0]->status());

        $staleIndex = $this->cache->getItem('zenstruck_messenger_monitor.worker_ids');
        $this->workerCache->remove(123);

        $workers = \iterator_to_array($this->workerCache);
        $this->assertCount(0, $workers);

        $this->cache->save($staleIndex);

        $this->assertCount(0, \iterator_to_array($this->workerCache));
    }

    /**
     * @test
     */
    public function update_worker_status(): void
    {
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);

        $this->workerCache->add(123, $metadata, 5, 1000);

        $this->workerCache->set(123, $metadata, WorkerInfo::PROCESSING, 10, 2000);

        $workers = \iterator_to_array($this->workerCache);
        $this->assertCount(1, $workers);
        $this->assertSame(WorkerInfo::PROCESSING, $workers[0]->status());
        $this->assertSame(10, $workers[0]->messagesHandled());
        $this->assertSame(2000, $workers[0]->memoryUsage()->value());
    }

    /**
     * @test
     */
    public function heartbeat_restores_the_index_and_preserves_the_start_time(): void
    {
        $clock = self::mockTime();
        $startedAt = $clock->now()->getTimestamp();
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);

        $this->workerCache->add(123, $metadata, 0, 1000);
        $this->cache->deleteItem('zenstruck_messenger_monitor.worker_ids');

        $clock->sleep(60);
        $this->workerCache->set(123, $metadata, WorkerInfo::PROCESSING, 1, 1000);

        $workers = \iterator_to_array($this->workerCache);
        $this->assertCount(1, $workers);
        $this->assertSame($startedAt, $workers[0]->startTime()->getTimestamp());
        $this->assertSame(WorkerInfo::PROCESSING, $workers[0]->status());
    }

    /**
     * @test
     */
    public function iterating_with_an_expired_worker_preserves_concurrent_registrations(): void
    {
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);
        $this->workerCache->add(123, $metadata, 0, 1000);
        $this->workerCache->add(456, $metadata, 0, 1000);
        $this->cache->deleteItem('zenstruck_messenger_monitor.worker.456');

        $snapshot = $this->workerCache->getIterator();
        $this->assertInstanceOf(\Iterator::class, $snapshot);
        $snapshot->rewind();
        $this->assertTrue($snapshot->valid());

        $this->workerCache->add(789, $metadata, 0, 1000);
        $snapshot->next();

        $this->assertFalse($snapshot->valid());
        $this->assertCount(2, \iterator_to_array($this->workerCache));
    }

    /**
     * @test
     */
    public function empty_cache_returns_empty_iterator(): void
    {
        $workers = \iterator_to_array($this->workerCache);
        $this->assertCount(0, $workers);
    }
}
