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
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Zenstruck\Messenger\Monitor\Stamp\ExecutionStamp;
use Zenstruck\Messenger\Monitor\Stamp\HandlerMetricsStamp;

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

        if (null !== $handler = $this->handlerName($event)) {
            $metrics = $envelope->last(HandlerMetricsStamp::class);
            $metrics?->finish($handler);
        }

        $stamp = $envelope->last(ExecutionStamp::class);
        $stamp?->finish();
    }

    public function onStart(object $event): void
    {
        $envelope = \get_object_vars($event)['envelope'] ?? null;
        $handler = $this->handlerName($event);

        if (!$envelope instanceof Envelope || null === $handler) {
            return;
        }

        $metrics = $envelope->last(HandlerMetricsStamp::class);
        $metrics?->start($handler);
    }

    private function handlerName(object $event): ?string
    {
        $handler = \get_object_vars($event)['handlerDescriptor'] ?? null;

        if (!$handler instanceof HandlerDescriptor || $handler->getBatchHandler()) {
            return null;
        }

        return $handler->getName();
    }
}
