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

use Zenstruck\Collection;
use Zenstruck\Messenger\Monitor\History\Model\MessageTypeMetric;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;

use function Symfony\Component\Clock\now;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class Snapshot
{
    private int $successCount;
    private int $failureCount;
    private float $averageWaitTime;
    private float $averageHandlingTime;
    private int $totalSeconds;

    public function __construct(private Storage $storage, private Specification $specification)
    {
    }

    public function specification(): Specification
    {
        return $this->specification;
    }

    /**
     * @return Collection<int,ProcessedMessage>
     */
    public function messages(): Collection
    {
        return $this->storage->filter($this->specification);
    }

    /**
     * @return Collection<int,MessageTypeMetric>
     */
    public function perMessageTypeMetrics(): Collection
    {
        return $this->storage->perMessageTypeMetrics($this->specification);
    }

    public function totalCount(): int
    {
        return $this->successCount() + $this->failureCount();
    }

    public function successCount(): int
    {
        return $this->successCount ??= $this->storage->count($this->specification->successes());
    }

    public function failureCount(): int
    {
        return $this->failureCount ??= $this->storage->count($this->specification->failures());
    }

    public function failRate(): float
    {
        try {
            return $this->failureCount() / $this->totalCount();
        } catch (\DivisionByZeroError) {
            return 0;
        }
    }

    public function averageWaitTime(): float
    {
        return $this->averageWaitTime ??= $this->storage->averageWaitTime($this->specification) ?? 0.0;
    }

    public function averageHandlingTime(): float
    {
        return $this->averageHandlingTime ??= $this->storage->averageHandlingTime($this->specification) ?? 0.0;
    }

    public function averageProcessingTime(): float
    {
        return $this->averageWaitTime() + $this->averageHandlingTime();
    }

    /**
     * @param positive-int $divisor Seconds
     */
    public function handledPer(int $divisor): float
    {
        $interval = $this->totalSeconds() / $divisor;

        return $this->totalCount() / $interval;
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

    public function totalSeconds(): int
    {
        if (isset($this->totalSeconds)) {
            return $this->totalSeconds;
        }

        [$from, $to] = \array_values($this->specification->toArray());

        if (!$from) {
            $from = $this->storage->filter(Specification::new()->sortAscending())->first()?->finishedAt();
        }

        if (!$from) {
            throw new \InvalidArgumentException('Specification filter must have a "from" date to use calculate "handled-per-x".');
        }

        return $this->totalSeconds = \abs(($to ?? now())->getTimestamp() - $from->getTimestamp());
    }
}
