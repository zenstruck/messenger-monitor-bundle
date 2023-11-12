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
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Zenstruck\Messenger\Monitor\History\Model\Result;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\Stamp\DisableMonitoringStamp;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 *
 * @phpstan-import-type Structure from Result
 */
final class HistoryListener
{
    public function __construct(private Storage $storage, private ResultNormalizer $resultNormalizer, private NormalizerInterface $normalizer)
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

            $event->addStamps(TagStamp::forSchedule($scheduledStamp));
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

        $event->addStamps($stamp->markFinished());

        $this->storage->save(
            $event->getEnvelope(),
            (array)$this->normalizer->normalize($event->getEnvelope()->getMessage()),
            $this->createResults($event->getEnvelope())
        );
    }

    public function handleFailure(WorkerMessageFailedEvent $event): void
    {
        if (!$stamp = $event->getEnvelope()->last(MonitorStamp::class)) {
            return;
        }

        if (!$stamp->isReceived()) {
            return;
        }

        $throwable = $event->getThrowable();

        $event->addStamps($stamp->markFinished());

        $this->storage->save(
            $event->getEnvelope(),
            (array)$this->normalizer->normalize($event->getEnvelope()),
            $this->createResults($event->getEnvelope(), $throwable instanceof HandlerFailedException ? $throwable : null),
            $throwable,
        );
    }

    private function isMonitoringDisabled(Envelope $envelope): bool
    {
        if ($envelope->last(DisableMonitoringStamp::class)) {
            return true;
        }

        if ((new \ReflectionClass($envelope->getMessage()))->getAttributes(DisableMonitoringStamp::class)) {
            return true;
        }

        return false;
    }

    private function createResults(Envelope $envelope, ?HandlerFailedException $exception = null): Results
    {
        $results = [];

        foreach ($envelope->all(HandledStamp::class) as $stamp) {
            /** @var HandledStamp $stamp */
            $results[] = [
                'handler' => $stamp->getHandlerName(),
                'data' => $this->resultNormalizer->normalize($stamp->getResult()),
            ];
        }

        if (!$exception) {
            return new Results($results);
        }

        foreach ($exception->getNestedExceptions() as $nested) {
            $results[] = [
                'exception' => $nested::class,
                'message' => $nested->getMessage(),
                'data' => $this->resultNormalizer->normalize($nested),
            ];
        }

        return new Results($results);
    }
}
