<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Fixture\Stub;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class ListableTransport implements TransportInterface, ListableReceiverInterface
{
    public function __construct(private array $envelopes = [])
    {
    }

    public function all(?int $limit = null): iterable
    {
        if (null === $limit) {
            return $this->envelopes;
        }

        return \array_slice($this->envelopes, 0, $limit);
    }

    public function find(mixed $id): ?Envelope
    {
        return null;
    }

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        return new Envelope(new \stdClass());
    }
}
