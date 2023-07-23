<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\History\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

use function Symfony\Component\Clock\now;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class MonitorStamp implements StampInterface
{
    private \DateTimeImmutable $dispatchedAt;
    private string $transport;
    private \DateTimeImmutable $receivedAt;

    public function __construct(?\DateTimeImmutable $dispatchedAt = null)
    {
        $this->dispatchedAt = $dispatchedAt ?? now();
    }

    public function markReceived(string $transport): self
    {
        $clone = clone $this;
        $clone->transport = $transport;
        $clone->receivedAt = now();

        return $clone;
    }

    public function isReceived(): bool
    {
        return isset($this->receivedAt);
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
}
