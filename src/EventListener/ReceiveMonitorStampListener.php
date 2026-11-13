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
use Symfony\Component\Messenger\Event\HandlerSuccessEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Zenstruck\Messenger\Monitor\Stamp\DisableInputStoreStamp;
use Zenstruck\Messenger\Monitor\Stamp\DisableMonitoringStamp;
use Zenstruck\Messenger\Monitor\Stamp\EnableInputStoreStamp;
use Zenstruck\Messenger\Monitor\Stamp\ExecutionStamp;
use Zenstruck\Messenger\Monitor\Stamp\HandlerMetricsStamp;
use Zenstruck\Messenger\Monitor\Stamp\InboxDeliveryStamp;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Stamp\SchedulerDeliveryStamp;
use Zenstruck\Messenger\Monitor\Stamp\StoredInputStamp;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class ReceiveMonitorStampListener
{
    /** @var array<class-string, bool> */
    private array $excluded = [];
    private bool $captureExecution;

    /**
     * @param class-string[] $excludedClasses
     */
    public function __construct(
        private array $excludedClasses,
        private ?NormalizerInterface $normalizer = null,
        private bool $storeInput = false,
    ) {
        $this->captureExecution = \class_exists(HandlerSuccessEvent::class);
    }

    public function __invoke(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();

        $stamp = $envelope->last(MonitorStamp::class);
        $scheduledStamp = $envelope->last(ScheduledStamp::class);

        if ((!$stamp && !$scheduledStamp) || $this->isMonitoringDisabled($envelope)) {
            return;
        }

        $stamps = [];

        if ($scheduledStamp) {
            // scheduler transport doesn't trigger SendMessageToTransportsEvent
            $stamp = new MonitorStamp($scheduledStamp->messageContext->triggeredAt);

            $delivery = new SchedulerDeliveryStamp($this->handledCount($envelope));
            $stamps[] = TagStamp::forSchedule($scheduledStamp);
            $stamps[] = $delivery;
        }

        if (!$stamp instanceof MonitorStamp) {
            return;
        }

        $stamps[] = $stamp->markReceived($event->getReceiverName());
        $stamps[] = new InboxDeliveryStamp();

        if ($this->captureExecution) {
            $stamps[] = new ExecutionStamp();
            $stamps[] = new HandlerMetricsStamp();
        }

        $input = $this->captureInput($envelope);

        if (null !== $input || $envelope->last(StoredInputStamp::class)) {
            $stamps[] = new StoredInputStamp($input);
        }

        $event->addStamps(...$stamps);
    }

    private function handledCount(Envelope $envelope): int
    {
        $message = $envelope->getMessage();

        if ($message instanceof RedispatchMessage) {
            $envelope = Envelope::wrap($message->envelope);
        }

        return \count($envelope->all(HandledStamp::class));
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

        if ($this->excluded[$messageClass] ??= $this->isExcluded($messageClass)) {
            return true;
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

    /** @param class-string $messageClass */
    private function isExcluded(string $messageClass): bool
    {
        foreach ($this->excludedClasses as $excludedClass) {
            if (\is_a($messageClass, $excludedClass, true)) {
                return true;
            }
        }

        return false;
    }
}
