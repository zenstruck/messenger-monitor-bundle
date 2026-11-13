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

use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Stamp\OutboxStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class AddMonitorStampListener
{
    public function __invoke(SendMessageToTransportsEvent $event): void
    {
        $envelope = $event->getEnvelope();

        if ($envelope->last(ReceivedStamp::class) && \class_exists(OutboxStamp::class) && isset($envelope->all()[OutboxStamp::class]) && $envelope->last(MonitorStamp::class)) {
            return;
        }

        $event->setEnvelope($envelope->with(new MonitorStamp()));
    }
}
