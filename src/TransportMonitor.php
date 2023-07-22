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
use Zenstruck\Messenger\Monitor\Transport\TransportInfo;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @implements \IteratorAggregate<string,TransportInfo<Envelope>>
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
     * @return TransportInfo<Envelope>
     */
    public function get(string $name): TransportInfo
    {
        if (!$this->transports->has($name)) {
            throw new \InvalidArgumentException(\sprintf('Transport "%s" does not exist.', $name));
        }

        return new TransportInfo($name, $this->transports->get($name)); // @phpstan-ignore-line
    }

    /**
     * @return array<string,TransportInfo<Envelope>>
     */
    public function all(): array
    {
        return \iterator_to_array($this);
    }

    /**
     * @return array<string,TransportInfo<Envelope>>
     */
    public function countable(): array
    {
        return \array_filter($this->all(), static fn(TransportInfo $status) => $status->isCountable());
    }

    /**
     * @return array<string,TransportInfo<Envelope>>
     */
    public function listable(): array
    {
        return \array_filter($this->all(), static fn(TransportInfo $status) => $status->isListable());
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
