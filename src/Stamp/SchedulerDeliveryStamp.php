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

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Sendable so forwarding decisions can return from parallel workers.
 *
 * @internal
 */
final class SchedulerDeliveryStamp implements StampInterface
{
    private bool $forwarded = false;

    public function __construct(private int $handledCount = 0)
    {
    }

    public function markForwarded(Envelope $envelope): void
    {
        $this->forwarded = $this->handledCount === \count($envelope->all(HandledStamp::class));
    }

    public function isForwarded(): bool
    {
        return $this->forwarded;
    }
}
