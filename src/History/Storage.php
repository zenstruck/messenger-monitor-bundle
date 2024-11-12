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
 */
interface Storage
{
    public function find(mixed $id): ?ProcessedMessage;

    /**
     * @return Collection<int,ProcessedMessage>
     */
    public function filter(Specification $specification): Collection;

    public function purge(Specification $specification): int;

    public function save(Envelope $envelope, Results $results, ?\Throwable $exception = null): void;

    public function delete(mixed $id): void;

    public function averageWaitTime(Specification $specification): ?float;

    public function averageHandlingTime(Specification $specification): ?float;

    public function count(Specification $specification): int;

    /**
     * @return Collection<int,MessageTypeMetric>
     */
    public function perMessageTypeMetrics(Specification $specification): Collection;

    /**
     * @return Collection<int,class-string>
     */
    public function availableMessageTypes(Specification $specification): Collection;
}
