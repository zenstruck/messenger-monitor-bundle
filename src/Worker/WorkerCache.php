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
        [$ids] = $this->ids();
        $registeredAt = $ids[$id] ?? null;
        $startedAt ??= $registeredAt;

        $worker = $this->cache->get(
            self::WORKER_KEY_PREFIX.$id,
            function(CacheItemInterface $item) use ($metadata, $status, $id, $messagesHandled, $memoryUsage, $startedAt) {
                $item->expiresAfter($this->expiredWorkerTtl);

                // Keep the start time when the shared index expires before the worker entry.
                $startedAt ??= $item->get()['startedAt'] ?? now()->getTimestamp();

                return [$metadata, $status, $id, $messagesHandled, $memoryUsage, 'startedAt' => $startedAt];
            },
            \INF, // force saving
        );

        if ($registeredAt === $worker['startedAt']) {
            return;
        }

        [$ids, $item] = $this->ids();
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

    /** @param array<int,int> $ids */
    private function saveIds(CacheItemInterface $item, array $ids): void
    {
        $item->set($ids);
        $item->expiresAfter($this->expiredWorkerTtl);

        $this->cache->save($item);
    }
}
