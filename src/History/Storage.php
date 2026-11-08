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

use Symfony\Component\Messenger\Envelope;
use Zenstruck\Collection;
use Zenstruck\Messenger\Monitor\History\Model\MessageTypeMetric;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;
use Zenstruck\Messenger\Monitor\History\Model\Results;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @phpstan-type Metrics array{success_count: int, failure_count: int, average_wait_time: int, average_handling_time: int}
 */
interface Storage
{
    public function find(mixed $id): ?ProcessedMessage;

    /**
     * @return Collection<ProcessedMessage, int>
     */
    public function filter(Specification $specification): Collection;

    public function purge(Specification $specification): int;

    public function save(Envelope $envelope, Results $results, ?\Throwable $exception = null): void;

    public function delete(mixed $id): void;

    /**
     * Counts include both statuses. Averages use the requested status and are in milliseconds, or zero when empty.
     *
     * @return Metrics
     */
    public function metrics(Specification $specification): array;

    /**
     * @return Collection<MessageTypeMetric, int>
     */
    public function perMessageTypeMetrics(Specification $specification): Collection;

    /**
     * @return Collection<class-string, int>
     */
    public function availableMessageTypes(Specification $specification): Collection;
}
