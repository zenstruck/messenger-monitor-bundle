<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\EventListener;

use Symfony\Component\Messenger\Envelope;
use Zenstruck\Messenger\Monitor\Stamp\ExecutionStamp;

/** @internal */
final class CaptureExecutionListener
{
    /** Handler event classes are unavailable on older supported Symfony versions. */
    public function __invoke(object $event): void
    {
        $envelope = \get_object_vars($event)['envelope'] ?? null;

        if (!$envelope instanceof Envelope) {
            return;
        }

        $stamp = $envelope->last(ExecutionStamp::class);
        $stamp?->finish();
    }
}
