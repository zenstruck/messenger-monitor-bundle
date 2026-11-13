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

use Psr\Container\ContainerInterface;

/** @internal */
final class CooldownStrategyLocator implements ContainerInterface
{
    public function __construct(private ContainerInterface $strategies, private WorkerListener $listener)
    {
    }

    public function get(string $id): MonitoringCooldownStrategy
    {
        return new MonitoringCooldownStrategy($this->strategies->get($id), $this->listener);
    }

    public function has(string $id): bool
    {
        return $this->strategies->has($id);
    }
}
