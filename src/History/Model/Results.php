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

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @implements \IteratorAggregate<Result>
 *
 * @phpstan-import-type Structure from Result
 */
final class Results implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var Result[] */
    private array $all;

    /**
     * @internal
     *
     * @param Structure[] $data
     */
    public function __construct(private array $data)
    {
    }

    /**
     * @return Result[]
     */
    public function all(): array
    {
        return $this->all ??= \array_map(static fn(array $result) => new Result($result), $this->data);
    }

    /**
     * @return Result[]
     */
    public function successes(): array
    {
        return \array_filter($this->all(), static fn(Result $result) => !$result->isFailure());
    }

    /**
     * @return Result[]
     */
    public function failures(): array
    {
        return \array_filter($this->all(), static fn(Result $result) => $result->isFailure());
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->all());
    }

    public function count(): int
    {
        return \count($this->data);
    }

    /**
     * @return Structure[]
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
