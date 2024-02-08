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

use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Zenstruck\Messenger\Monitor\History\Snapshot;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;

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

    /**
     * @return MessageInfo[]
     */
    public function messages(): array
    {
        // backwards compatibility with symfony/scheduler 6.3
        // @phpstan-ignore-next-line
        if (method_exists($this->task, 'getMessage')) {
            return [new MessageInfo($this->task->getMessage())];
        }

        $context = new MessageContext(
            $this->schedule->name(),
            $this->task->getId(),
            $this->task->getTrigger(),
            new \DateTimeImmutable(),
        );

        $messages = $this->task->getMessages($context);

        $messagesInfo = [];
        foreach($messages as $message) {
            $messagesInfo[] = new MessageInfo($message);
        }

        return $messagesInfo;
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
            ->with(TagStamp::forSchedule($this)->value)
            ->snapshot($this->storage ?? throw new \LogicException('No history storage configured.'))
        ;
    }
}
