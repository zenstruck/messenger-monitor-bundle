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

use Symfony\Component\Scheduler\RecurringMessage;
use Zenstruck\Messenger\Monitor\History\Snapshot;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Stamp\Tag;
use Zenstruck\Messenger\Monitor\Type;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @phpstan-import-type Input from Specification
 */
final class TaskInfo
{
    /**
     * @internal
     */
    public function __construct(
        private ScheduleInfo $schedule,
        private RecurringMessage $task,
        private ?Storage $storage,
    ) {
    }

    public function schedule(): ScheduleInfo
    {
        return $this->schedule;
    }

    public function get(): RecurringMessage
    {
        return $this->task;
    }

    public function id(): string
    {
        return $this->task->getId();
    }

    public function message(): MessageInfo
    {
        return new MessageInfo($this->task->getMessage());
    }

    public function trigger(): TriggerInfo
    {
        return new TriggerInfo($this->task->getTrigger());
    }

    /**
     * @param Specification|Input|null $specification
     */
    public function history(Specification|array|null $specification = null): Snapshot
    {
        return Specification::create($specification)
            ->with(Tag::forSchedule($this)->value)
            ->snapshot($this->storage ?? throw new \LogicException('No history storage configured.'))
        ;
    }
}
