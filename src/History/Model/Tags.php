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

use Symfony\Component\Messenger\Envelope;
use Zenstruck\Messenger\Monitor\Stamp\Tag;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @implements \IteratorAggregate<string>
 */
final class Tags implements \IteratorAggregate, \Countable, \Stringable
{
    /** @var string[] */
    private array $value;

    /**
     * @param string[]|string|Envelope|null $tags
     */
    public function __construct(array|string|Envelope|null $tags = [])
    {
        if ($tags instanceof Envelope) {
            $tags = \array_merge(...\array_map(fn(Tag $t) => $t->values, $tags->all(Tag::class))); // @phpstan-ignore-line
        }

        if (null === $tags) {
            $tags = [];
        }

        if (\is_string($tags)) {
            $tags = \explode(',', $tags);
        }

        $this->value = \array_values(\array_filter(\array_unique(\array_map('trim', $tags))));
    }

    public function __toString(): string
    {
        return (string) $this->implode();
    }

    public function implode(string $separator = ','): ?string
    {
        return $this->value ? \implode($separator, $this->value) : null;
    }

    /**
     * @return string[]
     */
    public function all(): array
    {
        return $this->value;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->value);
    }

    public function count(): int
    {
        return \count($this->value);
    }
}
