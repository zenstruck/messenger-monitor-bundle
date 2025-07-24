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
final class Snapshot extends Metric
{
    private int $successCount;
    private int $failureCount;
    private int $averageWaitTime;
    private int $averageHandlingTime;
    private int $totalSeconds;

    public function __construct(private Storage $storage, private Specification $specification)
    {
    }

    public function specification(): Specification
    {
        return $this->specification;
    }

    /**
     * @return Collection<ProcessedMessage, int>
     */
    public function messages(): Collection
    {
        return $this->storage->filter($this->specification);
    }

    /**
     * @return Collection<MessageTypeMetric, int>
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

    public function averageWaitTime(): int
    {
        return $this->averageWaitTime ??= $this->storage->averageWaitTime($this->specification) ?? 0;
    }

    public function averageHandlingTime(): int
    {
        return $this->averageHandlingTime ??= $this->storage->averageHandlingTime($this->specification) ?? 0;
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
