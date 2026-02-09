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
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\ResultNormalizer;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Stamp\DisableInputStoreStamp;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class HandleMonitorStampListener
{
    public function __construct(
        private Storage $storage,
        private ResultNormalizer $normalizer,
        private SerializerInterface $serializer
    ) {
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

        $input = null;
        if (!$this->isInputMonitoringDisabled($event->getEnvelope())) {
            /** @var array{body: string, headers?: array<string, string>} $input */
            $input = $this->serializer->encode($event->getEnvelope()->withoutAll(MonitorStamp::class));
        }

        $this->storage->save($event->getEnvelope(), $input, $this->createResults($event->getEnvelope()));
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

        $input = null;
        if (!$this->isInputMonitoringDisabled($event->getEnvelope())) {
            /** @var array{body: string, headers?: array<string, string>} $input */
            $input = $this->serializer->encode($event->getEnvelope()->withoutAll(MonitorStamp::class));
        }

        $this->storage->save(
            $event->getEnvelope(),
            $input,
            $this->createResults($event->getEnvelope(), $throwable instanceof HandlerFailedException ? $throwable : null),
            $throwable,
        );
    }

    private function createResults(Envelope $envelope, ?HandlerFailedException $exception = null): Results
    {
        $results = [];

        foreach ($envelope->all(HandledStamp::class) as $stamp) {
            /** @var HandledStamp $stamp */
            $results[] = [
                'handler' => $stamp->getHandlerName(),
                'data' => $this->normalizer->normalize($stamp->getResult()),
            ];
        }

        if (!$exception) {
            return new Results($results);
        }

        foreach ($exception->getWrappedExceptions() as $handler => $nested) {
            $results[] = [
                'handler' => $handler,
                'exception' => $nested::class,
                'message' => $nested->getMessage(),
                'data' => $this->normalizer->normalize($nested),
            ];
        }

        return new Results($results);
    }

    private function isInputMonitoringDisabled(Envelope $envelope): bool
    {
        if ($envelope->last(DisableInputStoreStamp::class)) {
            return true;
        }

        $reflection = new \ReflectionClass($envelope->getMessage());
        $attributes = [];

        while (false !== $reflection && [] === $attributes) {
            $attributes = $reflection->getAttributes(DisableInputStoreStamp::class);
            $reflection = $reflection->getParentClass();
        }

        return [] !== $attributes;
    }
}
