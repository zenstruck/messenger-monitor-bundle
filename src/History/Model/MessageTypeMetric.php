<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\History\Model;

use Zenstruck\Messenger\Monitor\Type;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class MessageTypeMetric
{
    public readonly Type $type;

    /**
     * @param class-string $class
     */
    public function __construct(
        string $class,
        public readonly int $totalCount,
        public readonly int $failureCount,
        public readonly float $averageWaitTime,
        public readonly float $averageHandlingTime,
        private readonly int $totalSeconds,
    ) {
        $this->type = new Type($class);
    }

    public function failRate(): float
    {
        try {
            return $this->failureCount / $this->totalCount;
        } catch (\DivisionByZeroError) {
            return 0;
        }
    }

    /**
     * @param positive-int $divisor Seconds
     */
    public function handledPer(int $divisor): float
    {
        $interval = $this->totalSeconds / $divisor;

        return $this->totalCount / $interval;
    }

    public function handledPerMinute(): float
    {
        return $this->handledPer(60);
    }

    public function handledPerHour(): float
    {
        return $this->handledPer(60 * 60);
    }

    public function handledPerDay(): float
    {
        return $this->handledPer(60 * 60 * 24);
    }
}
