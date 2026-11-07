<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\History;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
abstract class Metric
{
    final public function failRate(): float
    {
        try {
            return $this->failureCount() / $this->totalCount();
        } catch (\DivisionByZeroError) {
            return 0;
        }
    }

    /**
     * @param positive-int $divisor Seconds
     */
    final public function handledPer(int $divisor): float
    {
        // nothing handled yet, so there is no period to divide by
        if (0 === $totalCount = $this->totalCount()) {
            return 0.0;
        }

        $interval = $this->totalSeconds() / $divisor;

        return $totalCount / $interval;
    }

    final public function handledPerMinute(): float
    {
        return $this->handledPer(60);
    }

    final public function handledPerHour(): float
    {
        return $this->handledPer(60 * 60);
    }

    final public function handledPerDay(): float
    {
        return $this->handledPer(60 * 60 * 24);
    }

    /**
     * @return int milliseconds
     */
    final public function averageProcessingTime(): int
    {
        return $this->averageWaitTime() + $this->averageHandlingTime();
    }

    /**
     * @return int milliseconds
     */
    abstract public function averageWaitTime(): int;

    /**
     * @return int milliseconds
     */
    abstract public function averageHandlingTime(): int;

    abstract public function failureCount(): int;

    abstract public function totalCount(): int;

    abstract protected function totalSeconds(): int;
}
