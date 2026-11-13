<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Middleware;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Zenstruck\Messenger\Monitor\Stamp\InboxDeliveryStamp;

/** @internal */
final class InboxMonitoringMiddleware implements MiddlewareInterface
{
    public function __construct(private MiddlewareInterface $inbox)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$delivery = $envelope->last(InboxDeliveryStamp::class)) {
            return $this->inbox->handle($envelope, $stack);
        }

        $continued = false;
        $tracking = new class($stack, static function() use (&$continued): void {
            $continued = true;
        }) implements StackInterface {
            public function __construct(private StackInterface $stack, private \Closure $onContinue)
            {
            }

            public function __clone(): void
            {
                $this->stack = clone $this->stack;
            }

            public function next(): MiddlewareInterface
            {
                ($this->onContinue)();

                return $this->stack->next();
            }
        };
        $envelope = $this->inbox->handle($envelope, $tracking);

        if (!$continued) {
            $delivery->markSkipped();
        }

        return $envelope;
    }
}
