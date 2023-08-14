<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Transport;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Zenstruck\Messenger\Monitor\History\Model\Tags;
use Zenstruck\Messenger\Monitor\Stamp\DescriptionStamp;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Type;

final class QueuedMessage
{
    public function __construct(private Envelope $envelope)
    {
    }

    public function envelope(): Envelope
    {
        return $this->envelope;
    }

    public function id(): mixed
    {
        return $this->envelope->last(TransportMessageIdStamp::class)?->getId();
    }

    /**
     * @return Type<object>
     */
    public function message(): Type
    {
        return new Type(
            $this->envelope->getMessage(),
            $this->envelope->last(DescriptionStamp::class)?->value
        );
    }

    /**
     * @return Type<StampInterface>[]
     */
    public function stamps(): array
    {
        $stamps = \array_merge(...\array_values($this->envelope->all())); // @phpstan-ignore-line

        return \array_map(static fn(StampInterface $stamp) => new Type($stamp), $stamps);
    }

    public function tags(): Tags
    {
        return new Tags($this->envelope);
    }

    public function dispatchedAt(): ?\DateTimeImmutable
    {
        return $this->envelope->last(MonitorStamp::class)?->dispatchedAt();
    }

    /**
     * @return Type<\Throwable>|null
     */
    public function exception(): ?Type
    {
        if ($stamp = $this->envelope->last(ErrorDetailsStamp::class)) {
            return new Type($stamp->getExceptionClass(), $stamp->getExceptionMessage()); // @phpstan-ignore-line
        }

        return null;
    }
}
