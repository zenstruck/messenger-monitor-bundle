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

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\ResultNormalizer;
use Zenstruck\Messenger\Monitor\History\Storage;
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
        private ?LoggerInterface $logger = null,
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

        $this->save($event->getEnvelope());
    }

    public function handleFailure(WorkerMessageFailedEvent $event): void
    {
        if (!$stamp = $event->getEnvelope()->last(MonitorStamp::class)) {
            return;
        }

        if (!$stamp->isReceived()) {
            return;
        }

        $event->addStamps($stamp->markFinished());

        $this->save($event->getEnvelope(), $event->getThrowable());
    }

    private function save(Envelope $envelope, ?\Throwable $exception = null): void
    {
        try {
            $results = $this->createResults($envelope, $exception instanceof HandlerFailedException ? $exception : null);

            $this->storage->save($envelope, $results, $exception);
        } catch (\Throwable $error) {
            $this->logger?->error('Failed to save Messenger Monitor history for message "{class}".', [
                'class' => $envelope->getMessage()::class,
                'exception' => $error,
            ]);
        }
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
}
