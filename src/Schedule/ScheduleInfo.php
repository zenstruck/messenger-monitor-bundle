<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Schedule;

use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Zenstruck\Messenger\Monitor\History\Snapshot;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Transport\TransportInfo;
use Zenstruck\Messenger\Monitor\Transports;
use Zenstruck\Messenger\Monitor\Worker\WorkerInfo;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @implements \IteratorAggregate<TaskInfo>
 *
 * @phpstan-import-type Input from Specification
 */
final class ScheduleInfo implements \IteratorAggregate, \Countable
{
    /**
     * @internal
     */
    public function __construct(
        private string $name,
        private ScheduleProviderInterface $provider,
        private Transports $transports,
        private ?Storage $storage,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function get(): Schedule
    {
        return $this->provider->getSchedule();
    }

    public function task(string $id): TaskInfo
    {
        foreach ($this as $task) {
            if ($task->id() === $id) {
                return $task;
            }
        }

        throw new \InvalidArgumentException(\sprintf('Task "%s" not found.', $id));
    }

    /**
     * @param Specification|Input|null $specification
     */
    public function history(Specification|array|null $specification = null): Snapshot
    {
        return Specification::create($specification)
            ->with('schedule'.$this->name)
            ->snapshot($this->storage ?? throw new \LogicException('No history storage configured.'))
        ;
    }

    public function transport(): TransportInfo
    {
        return $this->transports->get('scheduler_'.$this->name);
    }

    /**
     * @return WorkerInfo[]
     */
    public function workers(): array
    {
        return $this->transport()->workers();
    }

    public function isRunning(): bool
    {
        return (bool) \count($this->workers());
    }

    public function getIterator(): \Traversable
    {
        foreach ($this->get()->getRecurringMessages() as $task) {
            yield new TaskInfo($this, $task, $this->storage);
        }
    }

    public function count(): int
    {
        return \count($this->get()->getRecurringMessages());
    }
}
