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
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class MonitorStamp implements StampInterface
{
    private int $runId;
    private \DateTimeImmutable $dispatchedAt;
    private string $transport;
    private \DateTimeImmutable $receivedAt;
    private \DateTimeImmutable $finishedAt;
    private int $memoryUsage;

    public function __construct(?\DateTimeImmutable $dispatchedAt = null)
    {
        $this->runId = \random_int(1, 1_000_000_000);
        $this->dispatchedAt = $dispatchedAt ?? now();
    }

    public function markReceived(string $transport): self
    {
        $clone = clone $this;
        $clone->transport = $transport;
        $clone->receivedAt = now();

        unset($clone->finishedAt, $clone->memoryUsage);

        return $clone;
    }

    public function markFinished(): self
    {
        if (!$this->isReceived()) {
            throw new \LogicException('Message not yet received.');
        }

        $clone = clone $this;
        $clone->finishedAt = now();
        $clone->memoryUsage = \memory_get_usage(true);

        return $clone;
    }

    public function isReceived(): bool
    {
        return isset($this->receivedAt);
    }

    public function isFinished(): bool
    {
        return isset($this->finishedAt);
    }

    public function runId(): int
    {
        return $this->runId;
    }

    public function dispatchedAt(): \DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    public function transport(): string
    {
        return $this->transport ?? throw new \LogicException('Message not yet received.');
    }

    public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt ?? throw new \LogicException('Message not yet received.');
    }

    public function finishedAt(): \DateTimeImmutable
    {
        return $this->finishedAt ?? throw new \LogicException('Message not yet finished.');
    }

    public function memoryUsage(): int
    {
        return $this->memoryUsage ?? throw new \LogicException('Message not yet finished.');
    }
}
