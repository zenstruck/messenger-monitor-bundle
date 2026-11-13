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

use function Symfony\Component\Clock\now;

final class TransportCooldown
{
    public function __construct(private string $transport, private ?\DateTimeImmutable $endsAt)
    {
    }

    public function transport(): string
    {
        return $this->transport;
    }

    public function endsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function isActive(): bool
    {
        return null === $this->endsAt || $this->endsAt > now();
    }
}
