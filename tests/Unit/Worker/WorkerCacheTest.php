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
use Symfony\Component\Cache\CacheItem;
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
    public function heartbeats_do_not_read_the_index_until_the_next_check_is_due(): void
    {
        $clock = self::mockTime();
        $cache = new IndexReadCountingCache();
        $workerCache = new WorkerCache($cache, 3600);
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);

        $workerCache->add(123, $metadata, 0, 1000);
        $cache->indexReads = 0;

        for ($i = 0; $i < 59; ++$i) {
            $clock->sleep(1);
            $workerCache->set(123, $metadata, WorkerInfo::IDLE, 0, 1000);
        }

        $this->assertSame(0, $cache->indexReads);

        $clock->sleep(1);
        $workerCache->set(123, $metadata, WorkerInfo::IDLE, 0, 1000);

        $this->assertSame(1, $cache->indexReads);
    }

    /**
     * @test
     */
    public function the_index_check_follows_a_short_ttl(): void
    {
        $clock = self::mockTime();
        $cache = new IndexReadCountingCache();
        $workerCache = new WorkerCache($cache, 300);
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);

        $workerCache->add(123, $metadata, 0, 1000);
        $cache->indexReads = 0;

        $clock->sleep(29);
        $workerCache->set(123, $metadata, WorkerInfo::IDLE, 0, 1000);
        $this->assertSame(0, $cache->indexReads);

        $clock->sleep(1);
        $workerCache->set(123, $metadata, WorkerInfo::IDLE, 0, 1000);
        $this->assertSame(1, $cache->indexReads);
    }

    /**
     * @test
     */
    public function registering_a_worker_drops_crashed_workers_from_the_index(): void
    {
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);
        $this->workerCache->add(123, $metadata, 0, 1000);
        $this->workerCache->add(456, $metadata, 0, 1000);

        // 456 crashed: it never removed itself and its entry expired.
        $this->cache->deleteItem('zenstruck_messenger_monitor.worker.456');

        $this->workerCache->add(789, $metadata, 0, 1000);

        $this->assertSame([123, 789], $this->indexedIds());
    }

    /**
     * @test
     */
    public function a_stale_index_write_does_not_keep_stopped_workers_in_the_index(): void
    {
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);

        foreach (\range(1, 11) as $id) {
            $this->workerCache->add($id, $metadata, 0, 1000);
        }

        // All workers stop at the same moment: each one read the index before the others wrote it,
        // so the last write puts the ids of the others back.
        $staleIndex = $this->cache->getItem('zenstruck_messenger_monitor.worker_ids');

        foreach (\range(1, 11) as $id) {
            $this->workerCache->remove($id);
        }

        $this->cache->save($staleIndex);
        $this->assertCount(11, $this->indexedIds());

        $this->workerCache->add(100, $metadata, 0, 1000);

        $this->assertSame([100], $this->indexedIds());
    }

    /**
     * @test
     */
    public function removing_a_worker_drops_crashed_workers_from_the_index(): void
    {
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);
        $this->workerCache->add(123, $metadata, 0, 1000);
        $this->workerCache->add(456, $metadata, 0, 1000);
        $this->workerCache->add(789, $metadata, 0, 1000);
        $this->cache->deleteItem('zenstruck_messenger_monitor.worker.456');

        $this->workerCache->remove(789);

        $this->assertSame([123], $this->indexedIds());
    }

    /**
     * @test
     */
    public function a_worker_whose_entry_expired_registers_again_on_its_next_heartbeat(): void
    {
        $clock = self::mockTime();
        $startedAt = $clock->now()->getTimestamp();
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);

        $this->workerCache->add(123, $metadata, 0, 1000);

        // 123 handles a message that runs longer than the ttl: its entry expires
        // and another worker starting meanwhile drops it from the index.
        $clock->sleep(10);
        $this->cache->deleteItem('zenstruck_messenger_monitor.worker.123');
        $this->workerCache->add(456, $metadata, 0, 1000);
        $this->assertSame([456], $this->indexedIds());

        $clock->sleep(1);
        $this->workerCache->set(123, $metadata, WorkerInfo::PROCESSING, 1, 1000);

        $this->assertSame([123, 456], $this->indexedIds());
        $this->assertCount(2, \iterator_to_array($this->workerCache));
    }

    /**
     * @test
     */
    public function an_expired_worker_entry_keeps_the_start_time_the_index_still_holds(): void
    {
        $clock = self::mockTime();
        $startedAt = $clock->now()->getTimestamp();
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);

        $this->workerCache->add(123, $metadata, 0, 1000);
        $this->cache->deleteItem('zenstruck_messenger_monitor.worker.123');

        $clock->sleep(500);
        $this->workerCache->set(123, $metadata, WorkerInfo::PROCESSING, 1, 1000);

        $workers = \iterator_to_array($this->workerCache);
        $this->assertCount(1, $workers);
        $this->assertSame($startedAt, $workers[0]->startTime()->getTimestamp());
    }

    /**
     * @test
     */
    public function empty_cache_returns_empty_iterator(): void
    {
        $workers = \iterator_to_array($this->workerCache);
        $this->assertCount(0, $workers);
    }

    /**
     * @return int[]
     */
    private function indexedIds(): array
    {
        $ids = \array_keys($this->cache->getItem('zenstruck_messenger_monitor.worker_ids')->get() ?? []);
        \sort($ids);

        return $ids;
    }
}

/**
 * @internal
 */
final class IndexReadCountingCache extends ArrayAdapter
{
    public int $indexReads = 0;

    public function getItem(mixed $key): CacheItem
    {
        if ('zenstruck_messenger_monitor.worker_ids' === $key) {
            ++$this->indexReads;
        }

        return parent::getItem($key);
    }
}
