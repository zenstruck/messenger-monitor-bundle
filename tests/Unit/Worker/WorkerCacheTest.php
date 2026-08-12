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
use Symfony\Component\Messenger\WorkerMetadata;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Worker\WorkerInfo;

use function Symfony\Component\Clock\now;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class WorkerCacheTest extends TestCase
{
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
    public function add_and_remove_workers(): void
    {
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);

        $this->workerCache->add(123, $metadata, 0, 1000);

        $workers = \iterator_to_array($this->workerCache);
        $this->assertCount(1, $workers);
        $this->assertSame(WorkerInfo::IDLE, $workers[0]->status());

        $this->workerCache->remove(123);

        $workers = \iterator_to_array($this->workerCache);
        $this->assertCount(0, $workers);
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
    public function id_list_has_proper_ttl(): void
    {
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);

        $this->workerCache->add(123, $metadata, 0, 1000);

        $idsItem = $this->cache->getItem('zenstruck_messenger_monitor.worker_ids');
        $this->assertTrue($idsItem->isHit());

        $reflection = new \ReflectionObject($this->cache);
        $expiriesProperty = $reflection->getProperty('expiries');
        $expiriesProperty->setAccessible(true);
        $expiries = $expiriesProperty->getValue($this->cache);

        $this->assertArrayHasKey('zenstruck_messenger_monitor.worker_ids', $expiries);
        $idListExpiry = $expiries['zenstruck_messenger_monitor.worker_ids'];

        // Expiry should be approximately now + 3600 seconds (with some tolerance for test execution time)
        $expectedExpiry = \time() + 3600;
        $this->assertGreaterThan(\time(), $idListExpiry, 'ID list expiry should be in the future');
        $this->assertLessThanOrEqual($expectedExpiry + 5, $idListExpiry, 'ID list expiry should be approximately 3600 seconds from now');

        $ids = $idsItem->get();
        $this->assertIsArray($ids);
        $this->assertArrayHasKey(123, $ids);
    }

    /**
     * @test
     */
    public function set_refreshes_id_list_ttl(): void
    {
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);

        $this->workerCache->add(123, $metadata, 0, 1000);

        // Get initial expiry time
        $reflection = new \ReflectionObject($this->cache);
        $expiriesProperty = $reflection->getProperty('expiries');
        $expiriesProperty->setAccessible(true);
        $initialExpiries = $expiriesProperty->getValue($this->cache);
        $initialExpiry = $initialExpiries['zenstruck_messenger_monitor.worker_ids'];

        // Wait a moment to ensure timestamp difference
        \usleep(100000); // 0.1 seconds

        // Update worker status (should refresh ID list TTL)
        $this->workerCache->set(123, $metadata, WorkerInfo::PROCESSING, 1, 1000);

        // Check that expiry time was updated
        $newExpiries = $expiriesProperty->getValue($this->cache);
        $newExpiry = $newExpiries['zenstruck_messenger_monitor.worker_ids'];

        $this->assertGreaterThanOrEqual($initialExpiry, $newExpiry, 'ID list TTL should be refreshed (not decreased)');

        // ID list should still be there and accessible
        $idsItem = $this->cache->getItem('zenstruck_messenger_monitor.worker_ids');
        $this->assertTrue($idsItem->isHit());
    }

    /**
     * @test
     */
    public function stale_worker_ids_are_cleaned_up_during_iteration(): void
    {
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);

        $this->workerCache->add(123, $metadata, 0, 1000);
        $this->workerCache->add(456, $metadata, 0, 1000);

        $workers = \iterator_to_array($this->workerCache);
        $this->assertCount(2, $workers);

        // Manually expire one worker's cache entry (simulating crashed worker)
        $this->cache->deleteItem('zenstruck_messenger_monitor.worker.456');

        // Get ID list to verify it still contains both IDs
        $idsItem = $this->cache->getItem('zenstruck_messenger_monitor.worker_ids');
        $ids = $idsItem->get();
        $this->assertCount(2, $ids);
        $this->assertArrayHasKey(123, $ids);
        $this->assertArrayHasKey(456, $ids);

        // Iterate through workers - this should clean up stale IDs
        $workers = \iterator_to_array($this->workerCache);
        $this->assertCount(1, $workers); // Only one active worker

        // Verify ID list was cleaned up
        $idsItem = $this->cache->getItem('zenstruck_messenger_monitor.worker_ids');
        $ids = $idsItem->get();
        $this->assertCount(1, $ids);
        $this->assertArrayHasKey(123, $ids);
        $this->assertArrayNotHasKey(456, $ids);
    }

    /**
     * @test
     */
    public function iteration_with_no_stale_ids_does_not_update_cache(): void
    {
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);

        $this->workerCache->add(123, $metadata, 0, 1000);

        $idsItem = $this->cache->getItem('zenstruck_messenger_monitor.worker_ids');
        $initialIds = $idsItem->get();

        // Iterate - should not trigger cache update since no cleanup is needed
        $workers = \iterator_to_array($this->workerCache);
        $this->assertCount(1, $workers);

        $idsItem = $this->cache->getItem('zenstruck_messenger_monitor.worker_ids');
        $currentIds = $idsItem->get();
        $this->assertEquals($initialIds, $currentIds, 'ID list should not be modified when no cleanup is needed');
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
