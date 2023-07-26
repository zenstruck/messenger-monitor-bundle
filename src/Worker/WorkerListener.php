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

use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class WorkerListener
{
    private int $id;
    private int $messagesHandled = 0;

    public function __construct(private WorkerCache $cache)
    {
        $this->id = \random_int(1, 1000000);
    }

    public function onStart(WorkerStartedEvent $event): void
    {
        $this->cache->add($this->id, $event->getWorker()->getMetadata(), $this->messagesHandled, \memory_get_usage(true));
    }

    public function onStop(): void
    {
        $this->cache->remove($this->id);
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        if (!$event->isWorkerIdle()) {
            ++$this->messagesHandled;
        }

        $this->cache->set(
            $this->id,
            $event->getWorker()->getMetadata(),
            $event->isWorkerIdle() ? WorkerInfo::IDLE : WorkerInfo::PROCESSING,
            $this->messagesHandled,
            \memory_get_usage(true),
        );
    }
}
