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
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
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
    /** @var string[] */
    private array $names;

    /**
     * @internal
     *
     * @param ServiceProviderInterface<TransportInterface> $transports
     */
    public function __construct(
        private ServiceProviderInterface $transports,
        private WorkerMonitor $workers,
    ) {
    }

    public function get(string $name): TransportInfo
    {
        if (!$this->transports->has($name)) {
            throw new \InvalidArgumentException(\sprintf('Transport "%s" does not exist.', $name));
        }

        return new TransportInfo($name, $this->transports->get($name), $this->workers);
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

    /**
     * @return string[]
     */
    public function names(): array
    {
        return $this->names ??= \array_filter(
            \array_keys($this->transports->getProvidedServices()),
            fn(string $name) => !$this->transports->get($name) instanceof SyncTransport,
        );
    }

    public function getIterator(): \Traversable
    {
        foreach ($this->names() as $name) {
            yield $name => $this->get($name);
        }
    }

    public function count(): int
    {
        return \count($this->names());
    }
}
