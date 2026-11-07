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
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Zenstruck\Messenger\Monitor\Stamp\DisableInputStoreStamp;
use Zenstruck\Messenger\Monitor\Stamp\DisableMonitoringStamp;
use Zenstruck\Messenger\Monitor\Stamp\EnableInputStoreStamp;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Stamp\StoredInputStamp;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class ReceiveMonitorStampListener
{
    /**
     * @param class-string[] $excludedClasses
     */
    public function __construct(
        private array $excludedClasses,
        private ?NormalizerInterface $normalizer = null,
        private bool $storeInput = false,
    ) {
    }

    public function __invoke(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();

        if ($this->isMonitoringDisabled($envelope)) {
            return;
        }

        $stamp = $envelope->last(MonitorStamp::class);

        if (\class_exists(ScheduledStamp::class) && $scheduledStamp = $envelope->last(ScheduledStamp::class)) {
            // scheduler transport doesn't trigger SendMessageToTransportsEvent
            $stamp = new MonitorStamp($scheduledStamp->messageContext->triggeredAt);

            $event->addStamps(TagStamp::forSchedule($scheduledStamp));
        }

        if (!$stamp instanceof MonitorStamp) {
            return;
        }

        $event->addStamps($stamp->markReceived($event->getReceiverName()));
        $input = $this->captureInput($envelope);

        if (null !== $input || $envelope->last(StoredInputStamp::class)) {
            $event->addStamps(new StoredInputStamp($input));
        }
    }

    /** @return array<array-key, mixed>|null */
    private function captureInput(Envelope $envelope): ?array
    {
        if (!$this->normalizer || (!$this->storeInput && !EnableInputStoreStamp::firstFrom($envelope))) {
            return null;
        }

        if (DisableInputStoreStamp::firstFrom($envelope)) {
            return null;
        }

        try {
            $input = $this->normalizer->normalize($envelope->getMessage(), 'json');

            $json = \json_encode($input, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
            $input = \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

            return \is_array($input) ? $input : ['data' => $input];
        } catch (\Throwable) {
            return null;
        }
    }

    private function isMonitoringDisabled(Envelope $envelope): bool
    {
        $messageClass = $envelope->getMessage()::class;

        foreach ($this->excludedClasses as $excludedClass) {
            if (\is_a($messageClass, $excludedClass, true)) {
                return true;
            }
        }

        if (!$stamp = DisableMonitoringStamp::firstFrom($envelope)) {
            return false;
        }

        if ($stamp->onlyWhenNoHandler && !$this->hasNoHandlers($envelope)) {
            return false;
        }

        return true;
    }

    private function hasNoHandlers(Envelope $envelope): bool
    {
        return [] === $envelope->all(HandledStamp::class);
    }
}
