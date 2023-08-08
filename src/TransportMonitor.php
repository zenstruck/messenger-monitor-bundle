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
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Zenstruck\Messenger\Monitor\Transport\TransportInfo;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @immutable
 *
 * @implements \IteratorAggregate<string,TransportInfo>
 */
final class TransportMonitor implements \IteratorAggregate, \Countable
{
    /** @var string[] */
    private array $names;

    /** @var (callable(TransportInterface,string):bool)[] */
    private array $filters = [];

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
     * @return array<string,TransportInfo>
     */
    public function all(): array
    {
        return \iterator_to_array($this);
    }

    public function countable(): self
    {
        return $this->filter(fn(TransportInterface $transport) => $transport instanceof MessageCountAwareInterface);
    }

    public function listable(): self
    {
        return $this->filter(fn(TransportInterface $transport) => $transport instanceof ListableReceiverInterface);
    }

    public function excludeSync(): self
    {
        return $this->filter(fn(TransportInterface $transport) => !$transport instanceof SyncTransport);
    }

    public function excludeSchedules(): self
    {
        return $this->filter(fn(TransportInterface $transport) => !$transport instanceof SchedulerTransport);
    }

    public function excludeFailed(): self
    {
        return $this->filter(fn(TransportInterface $transport, string $name) => !\str_contains($name, 'fail'));
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return $this->names ??= \array_filter(
            \array_keys($this->transports->getProvidedServices()),
            function(string $name) {
                $transport = $this->transports->get($name);

                foreach ($this->filters as $filter) {
                    if (!$filter($transport, $name)) {
                        return false;
                    }
                }

                return true;
            }
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

    /**
     * @param callable(TransportInterface,string):bool $filter
     */
    private function filter(callable $filter): self
    {
        $clone = clone $this;
        $clone->filters[] = $filter;

        unset($clone->names);

        return $clone;
    }
}
