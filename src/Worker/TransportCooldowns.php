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

/** @internal */
final class TransportCooldowns
{
    /** @param array<string, \DateTimeImmutable|null> $ends */
    public function __construct(private array $ends = [])
    {
    }

    public function update(string $transport, bool $coolingDown, ?\DateTimeImmutable $endsAt): bool
    {
        $exists = \array_key_exists($transport, $this->ends);

        if (!$coolingDown) {
            unset($this->ends[$transport]);

            return $exists;
        }

        if ($exists && $this->ends[$transport] == $endsAt) {
            return false;
        }

        $this->ends[$transport] = $endsAt;

        return true;
    }

    public function forTransport(string $transport): ?TransportCooldown
    {
        if (!\array_key_exists($transport, $this->ends)) {
            return null;
        }

        $cooldown = new TransportCooldown($transport, $this->ends[$transport]);

        return $cooldown->isActive() ? $cooldown : null;
    }

    /** @return array<string, \DateTimeImmutable|null> */
    public function values(): array
    {
        return $this->ends;
    }
}
