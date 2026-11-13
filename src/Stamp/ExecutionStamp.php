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

use function Symfony\Component\Clock\now;

/**
 * Sendable so measurements can return from a parallel worker.
 *
 * @internal
 */
final class ExecutionStamp implements StampInterface
{
    private ?\DateTimeImmutable $finishedAt = null;
    private ?int $memoryUsage = null;

    public function finish(): void
    {
        $this->finishedAt = now();
        $this->memoryUsage = \memory_get_usage(true);
    }

    public function finishMonitor(MonitorStamp $stamp): MonitorStamp
    {
        if (null === $this->finishedAt || null === $this->memoryUsage) {
            return $stamp->markFinished();
        }

        return MonitorStamp::from([
            ...$stamp->toArray(),
            'finishedAt' => $this->finishedAt,
            'memoryUsage' => $this->memoryUsage,
        ]);
    }
}
