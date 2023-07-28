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
use Zenstruck\Messenger\Monitor\Worker\WorkerInfo;
use Zenstruck\Messenger\Monitor\WorkerMonitor;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @immutable
 *
 * @template T of object
 * @implements \IteratorAggregate<T>
 */
final class TransportInfo implements \IteratorAggregate, \Countable
{
    private bool $envelopes = true;

    /** @var class-string|null */
    private ?string $class = null;

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

    public function transport(): TransportInterface
    {
        return $this->transport;
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
     * @return self<object>
     */
    public function messages(): self
    {
        $clone = clone $this;
        $clone->envelopes = false;

        return $clone;
    }

    /**
     * @return self<Envelope>
     */
    public function envelopes(): self
    {
        $clone = clone $this;
        $clone->envelopes = true;

        return $clone;
    }

    /**
     * @template C of object
     *
     * @param class-string<C> $class
     *
     * @return self<C>
     */
    public function of(string $class): self
    {
        $clone = clone $this;
        $clone->class = $class;

        return $clone;
    }

    /**
     * @return \Traversable<T>
     */
    public function list(?int $limit = null): \Traversable
    {
        if (!$this->transport instanceof ListableReceiverInterface) {
            throw new \LogicException(\sprintf('Transport "%s" does not implement "%s".', $this->name, ListableReceiverInterface::class));
        }

        foreach ($this->transport->all($limit) as $envelope) {
            if ($this->class && !\is_a($envelope->getMessage(), $this->class, true)) {
                continue;
            }

            yield $this->envelopes ? $envelope : $envelope->getMessage(); // @phpstan-ignore-line
        }
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
