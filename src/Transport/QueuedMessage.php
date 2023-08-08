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
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
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
        return new Type($this->envelope->getMessage());
    }

    /**
     * @return Type<StampInterface>[]
     */
    public function stamps(): array
    {
        return \array_map(static fn(StampInterface $stamp) => new Type($stamp), $this->envelope->all()); // @phpstan-ignore-line
    }
}
