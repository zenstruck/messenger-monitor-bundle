<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\History;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Zenstruck\Messenger\Monitor\History\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\History\Stamp\ResultStamp;
use Zenstruck\Messenger\Monitor\Stamp\DisableMonitoring;
use Zenstruck\Messenger\Monitor\Stamp\Tag;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class HistoryListener
{
    public function __construct(private Storage $storage, private ResultNormalizer $normalizer)
    {
    }

    public function addMonitorStamp(SendMessageToTransportsEvent $event): void
    {
        $event->setEnvelope($event->getEnvelope()->with(new MonitorStamp()));
    }

    public function receiveMessage(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();

        if ($this->isMonitoringDisabled($envelope)) {
            return;
        }

        $stamp = $envelope->last(MonitorStamp::class);

        if (\class_exists(ScheduledStamp::class) && $scheduledStamp = $envelope->last(ScheduledStamp::class)) {
            // scheduler transport doesn't trigger SendMessageToTransportsEvent
            $stamp = new MonitorStamp($scheduledStamp->messageContext->triggeredAt);

            $event->addStamps(Tag::forSchedule($scheduledStamp));
        }

        if ($stamp instanceof MonitorStamp) {
            $event->addStamps($stamp->markReceived($event->getReceiverName()));
        }
    }

    public function handleSuccess(WorkerMessageHandledEvent $event): void
    {
        if (!$stamp = $event->getEnvelope()->last(MonitorStamp::class)) {
            return;
        }

        if (!$stamp->isReceived()) {
            return;
        }

        if ($stamp = $event->getEnvelope()->last(HandledStamp::class)) {
            $event->addStamps(new ResultStamp($this->normalizer->normalize($stamp->getResult())));
        }

        $this->storage->save($event->getEnvelope());
    }

    public function handleFailure(WorkerMessageFailedEvent $event): void
    {
        if (!$stamp = $event->getEnvelope()->last(MonitorStamp::class)) {
            return;
        }

        if (!$stamp->isReceived()) {
            return;
        }

        $event->addStamps(new ResultStamp($this->normalizer->normalizeException($event->getThrowable())));

        $this->storage->save($event->getEnvelope(), $event->getThrowable());
    }

    private function isMonitoringDisabled(Envelope $envelope): bool
    {
        if ($envelope->last(DisableMonitoring::class)) {
            return true;
        }

        if ((new \ReflectionClass($envelope->getMessage()))->getAttributes(DisableMonitoring::class)) {
            return true;
        }

        return false;
    }
}
