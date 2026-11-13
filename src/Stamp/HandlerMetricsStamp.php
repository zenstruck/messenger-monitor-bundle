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

/**
 * Sendable so measurements can return from a parallel worker.
 *
 * @internal
 *
 * @phpstan-type Metrics array{duration_ms: int, memory_change: int}
 */
final class HandlerMetricsStamp implements StampInterface
{
    /** @var array<string, array{started_at: int|float, memory_before: int, duration_ms: ?int, memory_change: ?int}> */
    private array $handlers = [];

    public function start(string $handler): void
    {
        $this->handlers[$handler] = [
            'started_at' => \hrtime(true),
            'memory_before' => 0,
            'duration_ms' => null,
            'memory_change' => null,
        ];
        $this->handlers[$handler]['memory_before'] = \memory_get_usage();
    }

    public function finish(string $handler): void
    {
        $finishedAt = \hrtime(true);
        $memory = \memory_get_usage();
        if (!isset($this->handlers[$handler]) || null !== $this->handlers[$handler]['duration_ms']) {
            return;
        }

        $this->handlers[$handler]['duration_ms'] = (int) \round(($finishedAt - $this->handlers[$handler]['started_at']) / 1_000_000);
        $this->handlers[$handler]['memory_change'] = $memory - $this->handlers[$handler]['memory_before'];
    }

    /** @return Metrics|array{} */
    public function forHandler(string $handler): array
    {
        $measurement = $this->handlers[$handler] ?? null;

        if (null === ($measurement['duration_ms'] ?? null) || null === ($measurement['memory_change'] ?? null)) {
            return [];
        }

        return ['duration_ms' => $measurement['duration_ms'], 'memory_change' => $measurement['memory_change']];
    }
}
