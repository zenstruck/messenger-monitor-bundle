<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Zenstruck\Messenger\Monitor\Transport\TransportStatus;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @implements \IteratorAggregate<string,TransportStatus<Envelope>>
 */
final class TransportMonitor implements \IteratorAggregate, \Countable
{
    /**
     * @internal
     *
     * @param ServiceProviderInterface<TransportInterface> $transports
     */
    public function __construct(private ServiceProviderInterface $transports)
    {
    }

    /**
     * @return TransportStatus<Envelope>
     */
    public function get(string $name): TransportStatus
    {
        if (!$this->transports->has($name)) {
            throw new \InvalidArgumentException(\sprintf('Transport "%s" does not exist.', $name));
        }

        return new TransportStatus($name, $this->transports->get($name)); // @phpstan-ignore-line
    }

    /**
     * @return array<string,TransportStatus<Envelope>>
     */
    public function all(): array
    {
        return \iterator_to_array($this);
    }

    /**
     * @return array<string,TransportStatus<Envelope>>
     */
    public function countable(): array
    {
        return \array_filter($this->all(), static fn(TransportStatus $status) => $status->isCountable());
    }

    /**
     * @return array<string,TransportStatus<Envelope>>
     */
    public function listable(): array
    {
        return \array_filter($this->all(), static fn(TransportStatus $status) => $status->isListable());
    }

    public function getIterator(): \Traversable
    {
        foreach (\array_keys($this->transports->getProvidedServices()) as $name) {
            yield $name => $this->get($name);
        }
    }

    public function count(): int
    {
        return \count($this->transports->getProvidedServices());
    }
}
