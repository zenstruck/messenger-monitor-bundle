<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\History\Model;

use Zenstruck\Messenger\Monitor\Type;

/**
 * @internal
 *
 * @implements \IteratorAggregate<Attempt>
 * @phpstan-type Attempt array{
 *     id: int|string|\Stringable,
 *     messageId: string,
 *     causationId: ?string,
 *     type: class-string,
 *     description: ?string,
 *     transport: string,
 *     attempt: int,
 *     failureType: ?string,
 *     dispatchedAt: \DateTimeImmutable,
 *     receivedAt: \DateTimeImmutable,
 *     finishedAt: \DateTimeImmutable,
 *     handleTime: int|numeric-string,
 * }
 */
final class FlowMessage implements \Countable, \IteratorAggregate
{
    /** @param non-empty-list<Attempt> $attempts */
    public function __construct(private array $attempts)
    {
        \usort($this->attempts, static fn(array $left, array $right) => [$left['receivedAt'], $left['finishedAt'], (string) $left['id']] <=> [$right['receivedAt'], $right['finishedAt'], (string) $right['id']]);
    }

    public function messageId(): string
    {
        return $this->attempts[0]['messageId'];
    }

    public function causationId(): ?string
    {
        return $this->attempts[0]['causationId'];
    }

    public function type(): Type
    {
        return new Type($this->attempts[0]['type']);
    }

    /** @return Attempt */
    public function latest(): array
    {
        return $this->attempts[\count($this->attempts) - 1];
    }

    public function count(): int
    {
        return \count($this->attempts);
    }

    /** @return \Traversable<Attempt> */
    public function getIterator(): \Traversable
    {
        yield from $this->attempts;
    }
}
