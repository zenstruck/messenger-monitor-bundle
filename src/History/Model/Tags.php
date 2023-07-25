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
            $tags = \iterator_to_array(self::parseFrom($tags), preserve_keys: false);
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

    public function expand(): self
    {
        $clone = clone $this;

        $clone->value = \array_merge(
            ...\array_map(
                static function(string $tag): array {
                    $parts = \explode(':', $tag);

                    return \array_map(
                        static fn(int $i) => \implode(':', \array_slice($parts, 0, $i + 1)),
                        \array_keys($parts)
                    );
                },
                $this->value
            )
        );

        return $clone;
    }

    /**
     * @return \Traversable<string>
     */
    private static function parseFrom(Envelope $envelope): \Traversable
    {
        foreach ((new \ReflectionClass($envelope->getMessage()))->getAttributes(Tag::class) as $attribute) {
            yield from $attribute->newInstance()->values;
        }

        foreach ($envelope->all(Tag::class) as $tag) {
            yield from $tag->values; // @phpstan-ignore-line
        }
    }
}
