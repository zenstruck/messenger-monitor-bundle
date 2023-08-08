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
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Zenstruck\Collection;
use Zenstruck\Collection\FactoryCollection;
use Zenstruck\Messenger\Monitor\Worker\WorkerInfo;
use Zenstruck\Messenger\Monitor\WorkerMonitor;

use function Zenstruck\collect;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @implements \IteratorAggregate<QueuedMessage>
 */
final class TransportInfo implements \IteratorAggregate, \Countable
{
    public function __construct(
        private string $name,
        private TransportInterface $transport,
        private WorkerMonitor $workers,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function get(): TransportInterface
    {
        return $this->transport;
    }

    public function isFailure(): bool
    {
        return \str_contains($this->name, 'fail');
    }

    public function isCountable(): bool
    {
        return $this->transport instanceof MessageCountAwareInterface;
    }

    public function isListable(): bool
    {
        return $this->transport instanceof ListableReceiverInterface;
    }

    /**
     * @return Collection<int,QueuedMessage>
     */
    public function list(?int $limit = null): Collection
    {
        if (!$this->transport instanceof ListableReceiverInterface) {
            throw new \LogicException(\sprintf('Transport "%s" does not implement "%s".', $this->name, ListableReceiverInterface::class));
        }

        return new FactoryCollection(
            collect($this->transport->all($limit)),
            fn(Envelope $envelope) => new QueuedMessage($envelope)
        );
    }

    /**
     * @return WorkerInfo[]
     */
    public function workers(): array
    {
        return $this->workers->forTransport($this->name);
    }

    public function isRunning(): bool
    {
        return (bool) \count($this->workers());
    }

    public function getIterator(): \Traversable
    {
        yield from $this->list();
    }

    public function count(): int
    {
        if (!$this->transport instanceof MessageCountAwareInterface) {
            throw new \LogicException(\sprintf('Transport "%s" does not implement "%s".', $this->name, MessageCountAwareInterface::class));
        }

        return $this->transport->getMessageCount();
    }
}
