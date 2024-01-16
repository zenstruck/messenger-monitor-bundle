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
        [$ids, $item] = $this->ids();

        $ids[$id] = now()->getTimestamp();

        $item->set($ids);

        $this->cache->save($item);
        $this->set($id, $metadata, WorkerInfo::IDLE, $messagesHandled, $memoryUsage);
    }

    public function remove(int $id): void
    {
        [$ids, $item] = $this->ids();

        unset($ids[$id]);

        $item->set($ids);

        $this->cache->save($item);
    }

    /**
     * @param WorkerInfo::* $status
     */
    public function set(int $id, WorkerMetadata $metadata, string $status, int $messagesHandled, int $memoryUsage): void
    {
        $this->cache->get(
            self::WORKER_KEY_PREFIX.$id,
            function(CacheItemInterface $item) use ($metadata, $status, $id, $messagesHandled, $memoryUsage) {
                $item->expiresAfter($this->expiredWorkerTtl);

                return [$metadata, $status, $id, $messagesHandled, $memoryUsage];
            },
            \INF, // force saving
        );
    }

    public function getIterator(): \Traversable
    {
        /** @var array<int,int> $ids */
        $ids = $this->cache->get(
            self::ID_LIST_KEY,
            fn() => [],
            0, // never perform early expiration
        );

        $keys = \array_map(
            static fn(int $id) => self::WORKER_KEY_PREFIX.$id,
            \array_keys($ids),
        );

        foreach ($this->cache->getItems($keys) as $item) {
            [$metadata, $status, $id, $messagesHandled, $memoryUsage] = $item->get();

            if ($id) {
                yield new WorkerInfo($metadata, $status, $ids[$id], $messagesHandled, $memoryUsage);
            }
        }
    }

    /**
     * @return array{array<int,int>, CacheItemInterface}
     */
    private function ids(): array
    {
        $item = $this->cache->getItem(self::ID_LIST_KEY);

        if (!\is_array($ids = $item->get() ?? [])) {
            $ids = [];
        }

        return [$ids, $item];
    }
}
