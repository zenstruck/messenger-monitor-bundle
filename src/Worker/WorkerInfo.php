<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Worker;

use Symfony\Component\Messenger\WorkerMetadata;
use Zenstruck\Bytes;

use function Symfony\Component\Clock\now;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class WorkerInfo
{
    public const IDLE = 'idle';
    public const PROCESSING = 'processing';

    /**
     * @internal
     *
     * @param self::* $status
     */
    public function __construct(
        private WorkerMetadata $metadata,
        private string $status,
        private int $startTime,
        private int $messagesHandled,
        private int $memoryUsage,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function startTime(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('U', (string) $this->startTime) ?: throw new \RuntimeException('Failed to determine start time.');
    }

    /**
     * @return int Number of seconds the worker has been running
     */
    public function runningFor(): int
    {
        return now()->getTimestamp() - $this->startTime;
    }

    /**
     * @return string[]
     */
    public function transports(): array
    {
        return $this->metadata->getTransportNames();
    }

    /**
     * @return string[]
     */
    public function queues(): array
    {
        return $this->metadata->getQueueNames() ?? [];
    }

    public function isIdle(): bool
    {
        return self::IDLE === $this->status;
    }

    public function isProcessing(): bool
    {
        return self::PROCESSING === $this->status;
    }

    public function messagesHandled(): int
    {
        return $this->messagesHandled;
    }

    public function memoryUsage(): Bytes
    {
        return new Bytes($this->memoryUsage);
    }
}
