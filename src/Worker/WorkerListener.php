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

use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\WorkerMetadata;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class WorkerListener
{
    private int $id;
    private int $messagesHandled = 0;
    private int $messagesInFlight = 0;
    private ?WorkerMetadata $metadata = null;

    public function __construct(private WorkerCache $cache)
    {
        $this->id = \random_int(1, 1000000);
    }

    public function onStart(WorkerStartedEvent $event): void
    {
        $worker = $event->getWorker();
        $this->metadata = $worker->getMetadata();
        $this->cache->add($this->id, $this->metadata, $this->messagesHandled, \memory_get_usage(true));
    }

    public function onStop(): void
    {
        $this->cache->remove($this->id);
        $this->metadata = null;
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        $this->refresh();
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        if (!$this->metadata || !$event->shouldHandle()) {
            return;
        }

        ++$this->messagesInFlight;
        $this->refresh();
    }

    public function onMessageFinished(WorkerMessageHandledEvent|WorkerMessageFailedEvent $event): void
    {
        if (!$this->metadata) {
            return;
        }

        $this->messagesInFlight = \max(0, $this->messagesInFlight - 1);
        ++$this->messagesHandled;
        $this->refresh();
    }

    private function refresh(): void
    {
        if (!$this->metadata) {
            return;
        }

        $this->cache->set(
            $this->id,
            $this->metadata,
            $this->messagesInFlight ? WorkerInfo::PROCESSING : WorkerInfo::IDLE,
            $this->messagesHandled,
            \memory_get_usage(true),
        );
    }
}
