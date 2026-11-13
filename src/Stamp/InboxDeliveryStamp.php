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
 * Sendable so inbox skips can return from parallel workers.
 *
 * @internal
 */
final class InboxDeliveryStamp implements StampInterface
{
    private bool $skipped = false;

    public function markSkipped(): void
    {
        $this->skipped = true;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }
}
