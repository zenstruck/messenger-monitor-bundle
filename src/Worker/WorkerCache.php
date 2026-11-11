<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Worker;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Messenger\WorkerMetadata;
use Symfony\Contracts\Cache\CacheInterface;

use function Symfony\Component\Clock\now;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 *
 * @implements \IteratorAggregate<WorkerInfo>
 */
final class WorkerCache implements \IteratorAggregate
{
    private const ID_LIST_KEY = 'zenstruck_messenger_monitor.worker_ids';
    private const WORKER_KEY_PREFIX = 'zenstruck_messenger_monitor.worker.';
    private const MAX_INDEX_CHECK_INTERVAL = 60;

    /** @var array<int,int> Per worker id, when its heartbeat next compares itself with the shared index */
    private array $nextIndexCheckAt = [];

    /** @var array<int,int> Start time of the workers this process reported, kept when the cache loses it */
    private array $startedAt = [];

    public function __construct(
        private CacheItemPoolInterface&CacheInterface $cache,
        private int $expiredWorkerTtl = 3600,
    ) {
    }

    public function add(int $id, WorkerMetadata $metadata, int $messagesHandled, int $memoryUsage): void
    {
        $this->set($id, $metadata, WorkerInfo::IDLE, $messagesHandled, $memoryUsage, now()->getTimestamp());
    }

    public function remove(int $id): void
    {
        unset($this->nextIndexCheckAt[$id], $this->startedAt[$id]);

        $this->cache->deleteItem(self::WORKER_KEY_PREFIX.$id);

        [$ids, $item] = $this->ids();

        unset($ids[$id]);

        $this->saveIds($item, $ids);
    }

    /**
     * @param WorkerInfo::* $status
     */
    public function set(
        int $id,
        WorkerMetadata $metadata,
        string $status,
        int $messagesHandled,
        int $memoryUsage,
        ?int $startedAt = null,
    ): void {
        $entryWasMissing = false;
        $startedAt ??= $this->startedAt[$id] ?? null;

        $worker = $this->cache->get(
            self::WORKER_KEY_PREFIX.$id,
            function(CacheItemInterface $item) use ($metadata, $status, $id, $messagesHandled, $memoryUsage, $startedAt, &$entryWasMissing) {
                $item->expiresAfter($this->expiredWorkerTtl);

                $entryWasMissing = !$item->isHit();

                // A worker reported from another process (or before this one knew it): take the start time
                // from its entry, or from the shared index when the entry expired.
                $startedAt ??= $item->get()['startedAt'] ?? $this->ids()[0][$id] ?? now()->getTimestamp();

                return [$metadata, $status, $id, $messagesHandled, $memoryUsage, 'startedAt' => $startedAt];
            },
            \INF, // force saving
        );

        // Remember the start time in this process. When a message runs longer than the ttl, the worker entry
        // expires and another worker's registration prunes this one from the index, so the cache no longer
        // holds it and the next heartbeat would restart the uptime.
        $this->startedAt[$id] = $worker['startedAt'];

        // The shared index holds every worker and is only needed to register this one. Reading it on each
        // heartbeat makes every worker fetch the whole index on every loop, so only compare when the worker
        // entry was missing (new worker, or expired and possibly pruned) or when the periodic check is due.
        $now = now()->getTimestamp();

        if (!$entryWasMissing && $now < ($this->nextIndexCheckAt[$id] ?? 0)) {
            return;
        }

        $this->nextIndexCheckAt[$id] = $now + $this->indexCheckInterval();

        [$ids, $item] = $this->ids();

        if (($ids[$id] ?? null) === $worker['startedAt']) {
            return;
        }

        $ids[$id] = $worker['startedAt'];

        $this->saveIds($item, $ids);
    }

    public function getIterator(): \Traversable
    {
        [$ids] = $this->ids();

        $keys = \array_map(
            static fn(int $id) => self::WORKER_KEY_PREFIX.$id,
            \array_keys($ids),
        );

        foreach ($this->cache->getItems($keys) as $item) {
            if (!$item->isHit()) {
                continue;
            }

            [$metadata, $status, $id, $messagesHandled, $memoryUsage] = $item->get();

            yield new WorkerInfo($metadata, $status, $ids[$id], $messagesHandled, $memoryUsage);
        }
    }

    /**
     * @return array{array<int,int>, CacheItemInterface}
     */
    private function ids(): array
    {
        $item = $this->cache->getItem(self::ID_LIST_KEY);

        $ids = $item->get();

        return [\is_array($ids) ? $ids : [], $item];
    }

    /**
     * Workers write the index without a lock, so a stale write can bring back the id of a worker that already
     * stopped, and a worker that crashed never removes its id. Without this the index only grows: every write
     * drops the ids whose worker entry no longer exists. A running worker refreshes its entry on each heartbeat
     * and registers again when its entry had expired, so it is never lost for long.
     *
     * @param array<int,int> $ids
     */
    private function saveIds(CacheItemInterface $item, array $ids): void
    {
        $item->set($this->withoutExpiredWorkers($ids));
        $item->expiresAfter($this->expiredWorkerTtl);

        $this->cache->save($item);
    }

    /**
     * @param array<int,int> $ids
     *
     * @return array<int,int>
     */
    private function withoutExpiredWorkers(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $keys = \array_map(
            static fn(int $id) => self::WORKER_KEY_PREFIX.$id,
            \array_keys($ids),
        );

        $running = [];

        foreach ($this->cache->getItems($keys) as $key => $workerItem) {
            if ($workerItem->isHit()) {
                $id = (int) \mb_substr($key, \mb_strlen(self::WORKER_KEY_PREFIX));
                $running[$id] = $ids[$id];
            }
        }

        return $running;
    }

    private function indexCheckInterval(): int
    {
        return \min(self::MAX_INDEX_CHECK_INTERVAL, \max(1, \intdiv($this->expiredWorkerTtl, 10)));
    }
}
