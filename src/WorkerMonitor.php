<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor;

use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Worker\WorkerInfo;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class WorkerMonitor implements \Countable
{
    /**
     * @internal
     */
    public function __construct(private WorkerCache $cache)
    {
    }

    public function isRunning(): bool
    {
        return (bool) $this->count();
    }

    /**
     * @return WorkerInfo[]
     */
    public function all(): array
    {
        return \iterator_to_array($this->cache);
    }

    /**
     * @return WorkerInfo[]
     */
    public function forTransport(string $name): array
    {
        return \array_filter(
            $this->all(),
            static fn(WorkerInfo $info) => \in_array($name, $info->transports(), true),
        );
    }

    /**
     * @return WorkerInfo[]
     */
    public function forQueue(string $name): array
    {
        return \array_filter(
            $this->all(),
            static fn(WorkerInfo $info) => \in_array($name, $info->queues(), true),
        );
    }

    public function count(): int
    {
        return \count($this->all());
    }
}
