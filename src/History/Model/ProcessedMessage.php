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
use Zenstruck\Messenger\Monitor\History\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\History\Stamp\ResultStamp;

use function Symfony\Component\Clock\now;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
class ProcessedMessage
{
    private int $runId;
    private int $attempt = 1;

    /** @var class-string */
    private string $type;
    private \DateTimeImmutable $dispatchedAt;
    private \DateTimeImmutable $receivedAt;
    private \DateTimeImmutable $handledAt;
    private string $transport;

    /** @var string[] */
    private array $tags;
    private ?string $failure = null;

    /** @var array<string,mixed> */
    private array $result = [];

    final public function __construct(Envelope $envelope, ?\Throwable $exception = null)
    {
        $monitorStamp = $envelope->last(MonitorStamp::class) ?? throw new \LogicException('Required stamp not available');

        $this->runId = $monitorStamp->runId();
        $this->type = $envelope->getMessage()::class;
        $this->dispatchedAt = $monitorStamp->dispatchedAt();
        $this->receivedAt = $monitorStamp->receivedAt();
        $this->handledAt = now();
        $this->transport = $monitorStamp->transport();
        $this->tags = (new Tags($envelope))->all();

        if ($retryStamp = $envelope->last(RedeliveryStamp::class)) {
            $this->attempt += $retryStamp->getRetryCount();
        }

        if ($resultStamp = $envelope->last(ResultStamp::class)) {
            $this->result = $resultStamp->value;
        }

        if ($exception) {
            $this->failure = new Failure($exception);
        }
    }

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

    final public function dispatchedAt(): \DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    final public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    final public function handledAt(): \DateTimeImmutable
    {
        return $this->handledAt;
    }

    final public function transport(): string
    {
        return $this->transport;
    }

    final public function tags(): Tags
    {
        return new Tags($this->tags);
    }

    /**
     * @return array<string,mixed>
     */
    final public function result(): array
    {
        return $this->result;
    }

    final public function failure(): ?Failure
    {
        return $this->failure ? new Failure($this->failure) : null;
    }

    final public function isFailure(): bool
    {
        return null !== $this->failure;
    }

    final public function timeInQueue(): int
    {
        return \max(0, $this->receivedAt->getTimestamp() - $this->dispatchedAt->getTimestamp());
    }

    final public function timeToHandle(): int
    {
        return \max(0, $this->handledAt->getTimestamp() - $this->receivedAt->getTimestamp());
    }

    final public function timeToProcess(): int
    {
        return \max(0, $this->handledAt->getTimestamp() - $this->dispatchedAt->getTimestamp());
    }
}
