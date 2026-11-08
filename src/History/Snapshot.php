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
 *
 * @phpstan-import-type Metrics from Storage
 */
final class Snapshot extends Metric
{
    /** @var Metrics */
    private array $metrics;
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
        return $this->metrics()['success_count'];
    }

    public function failureCount(): int
    {
        return $this->metrics()['failure_count'];
    }

    public function averageWaitTime(): int
    {
        return $this->metrics()['average_wait_time'];
    }

    public function averageHandlingTime(): int
    {
        return $this->metrics()['average_handling_time'];
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

    /** @return Metrics */
    private function metrics(): array
    {
        return $this->metrics ??= $this->storage->metrics($this->specification);
    }
}
