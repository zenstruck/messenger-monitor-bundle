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

use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Zenstruck\Messenger\Monitor\Transport\TransportFilter;
use Zenstruck\Messenger\Monitor\Transport\TransportInfo;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @immutable
 *
 * @implements \IteratorAggregate<string,TransportInfo>
 */
final class Transports implements \IteratorAggregate, \Countable
{
    private TransportInfo $failure;

    /**
     * @internal
     *
     * @param ServiceProviderInterface<TransportInterface> $transports
     */
    public function __construct(
        private ServiceProviderInterface $transports,
        private Workers $workers,
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
     * @return array<string,TransportInfo>
     */
    public function all(): array
    {
        return \iterator_to_array($this);
    }

    public function filter(): TransportFilter
    {
        return new TransportFilter($this->transports, $this->workers);
    }

    public function failure(): ?TransportInfo
    {
        if (isset($this->failure)) {
            return $this->failure;
        }

        foreach (\array_keys($this->transports->getProvidedServices()) as $name) {
            if (\str_contains($name, 'fail')) {
                return $this->failure = $this->get($name);
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return \array_keys($this->transports->getProvidedServices());
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
