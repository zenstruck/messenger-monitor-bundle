<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Zenstruck\Messenger\Monitor\Schedule\TaskInfo;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class TagStamp implements StampInterface, \Stringable
{
    public function __construct(
        public readonly string $value,
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function forSchedule(ScheduledStamp|TaskInfo $value): self
    {
        if ($value instanceof ScheduledStamp) {
            return new self(\sprintf('schedule:%s:%s', $value->messageContext->name, $value->messageContext->id));
        }

        return new self(\sprintf('schedule:%s:%s', $value->schedule()->name(), $value->id()));
    }
}
