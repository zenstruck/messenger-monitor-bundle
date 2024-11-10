<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\History\Model;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Zenstruck\Bytes;
use Zenstruck\Messenger\Monitor\Stamp\DescriptionStamp;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Type;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @phpstan-import-type Structure from Result
 */
abstract class ProcessedMessage
{
    private int $runId;
    private int $attempt = 1;

    /** @var class-string */
    private string $type;
    private ?string $description;
    private \DateTimeImmutable $dispatchedAt;
    private \DateTimeImmutable $receivedAt;
    private \DateTimeImmutable $finishedAt;
    private int $memoryUsage;
    private string $transport;
    private ?string $tags;
    private int $waitTime;
    private int $handleTime;

    /** @var class-string<\Throwable> */
    private ?string $failureType = null;
    private ?string $failureMessage = null;

    /** @var Structure[]|Results */
    private array|Results $results;

    public function __construct(Envelope $envelope, Results $results, ?\Throwable $exception = null)
    {
        $monitorStamp = $envelope->last(MonitorStamp::class) ?? throw new \LogicException('Required stamp not available');
        $type = new Type($envelope->getMessage());
        $tags = new Tags($envelope);

        $this->runId = $monitorStamp->runId();
        $this->type = $type->class();
        $this->description = $envelope->last(DescriptionStamp::class)?->value ?? $type->description();
        $this->dispatchedAt = $monitorStamp->dispatchedAt();
        $this->receivedAt = $monitorStamp->receivedAt();
        $this->finishedAt = $monitorStamp->finishedAt();
        $this->memoryUsage = $monitorStamp->memoryUsage();
        $this->transport = $monitorStamp->transport();
        $this->tags = $tags->count() ? (string) $tags : null;
        $this->results = $results;
        $this->waitTime = \max(0, $this->receivedAt->getTimestamp() - $this->dispatchedAt->getTimestamp());
        $this->handleTime = \max(0, $this->finishedAt->getTimestamp() - $this->receivedAt->getTimestamp());

        if ($retryStamp = $envelope->last(RedeliveryStamp::class)) {
            $this->attempt += $retryStamp->getRetryCount();
        }

        if ($exception) {
            $this->failureType = $exception::class;
            $this->failureMessage = $exception->getMessage();
        }
    }

    abstract public function id(): string|int|\Stringable|null;

    final public function runId(): int
    {
        return $this->runId;
    }

    final public function attempt(): int
    {
        return $this->attempt;
    }

    final public function type(): Type
    {
        return new Type($this->type);
    }

    final public function description(): ?string
    {
        return $this->description;
    }

    final public function dispatchedAt(): \DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    final public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    final public function finishedAt(): \DateTimeImmutable
    {
        return $this->finishedAt;
    }

    final public function transport(): string
    {
        return $this->transport;
    }

    final public function tags(): Tags
    {
        return new Tags($this->tags);
    }

    final public function results(): Results
    {
        if ($this->results instanceof Results) {
            return $this->results;
        }

        return $this->results = new Results($this->results);
    }

    /**
     * @return Type<\Throwable>|null
     */
    final public function failure(): ?Type
    {
        return $this->failureType ? new Type($this->failureType, $this->failureMessage) : null;
    }

    final public function isFailure(): bool
    {
        return null !== $this->failureType;
    }

    final public function timeInQueue(): int
    {
        return $this->waitTime;
    }

    final public function timeToHandle(): int
    {
        return $this->handleTime;
    }

    final public function timeToProcess(): int
    {
        return $this->waitTime + $this->handleTime;
    }

    final public function memoryUsage(): Bytes
    {
        return new Bytes($this->memoryUsage);
    }
}
