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
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Zenstruck\Messenger\Monitor\Stamp\SchedulerDeliveryStamp;

/** @internal */
final class SchedulerMonitoringMiddleware implements MiddlewareInterface
{
    /** @var \WeakMap<MessageContext, SchedulerDeliveryStamp> */
    private \WeakMap $deliveries;

    public function __construct()
    {
        $this->deliveries = new \WeakMap();
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $delivery = $this->delivery($envelope);
        $scheduled = $envelope->last(ScheduledStamp::class);

        // Older Symfony forwards a separate envelope sharing the wrapper's message context.
        if ($delivery && $scheduled && $envelope->getMessage() instanceof RedispatchMessage) {
            $context = $scheduled->messageContext;
            $this->deliveries[$context] = $delivery;
        }

        $sent = $envelope->last(SentStamp::class);
        $next = $stack->next();
        $envelope = $next->handle($envelope, $stack);
        $forwarded = $envelope->last(SentStamp::class);

        if ($delivery && $forwarded && $sent !== $forwarded) {
            $delivery->markForwarded($envelope);
        }

        return $envelope;
    }

    private function delivery(Envelope $envelope): ?SchedulerDeliveryStamp
    {
        if ($delivery = $envelope->last(SchedulerDeliveryStamp::class)) {
            return $delivery;
        }

        $scheduled = $envelope->last(ScheduledStamp::class);

        if (!$scheduled) {
            return null;
        }

        $context = $scheduled->messageContext;

        return $this->deliveries[$context] ?? null;
    }
}
