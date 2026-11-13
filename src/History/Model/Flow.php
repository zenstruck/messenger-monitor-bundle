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
 * @internal
 *
 * @implements \IteratorAggregate<array{message: FlowMessage, depth: int}>
 * @phpstan-import-type Attempt from FlowMessage
 */
final class Flow implements \Countable, \IteratorAggregate
{
    /** @var array<string, FlowMessage> */
    private array $messages = [];

    /** @param list<Attempt> $attempts */
    public function __construct(array $attempts = [])
    {
        $groups = [];

        foreach ($attempts as $attempt) {
            $groups['message:'.$attempt['messageId']][] = $attempt;
        }

        foreach ($groups as $key => $group) {
            $this->messages[$key] = new FlowMessage($group);
        }
    }

    public function count(): int
    {
        return \count($this->messages);
    }

    public function getIterator(): \Traversable
    {
        [$roots, $children] = $this->links();
        $visited = [];
        $pending = \array_map(static fn(string $key) => [$key, 0], \array_reverse($roots));

        yield from $this->walk($pending, $children, $visited);

        // Keep orphaned cycles visible without revisiting a message.
        foreach (\array_keys($this->messages) as $key) {
            yield from $this->walk([[$key, 0]], $children, $visited);
        }
    }

    /** @return array{list<string>, array<string, list<string>>} */
    private function links(): array
    {
        $roots = $children = [];

        foreach ($this->messages as $key => $message) {
            $causation = $message->causationId();
            $parent = 'message:'.$causation;

            if (null !== $causation && $parent !== $key && isset($this->messages[$parent])) {
                $children[$parent][] = $key;

                continue;
            }

            $roots[] = $key;
        }

        return [$roots, $children];
    }

    /**
     * @param list<array{string, int}>    $pending
     * @param array<string, list<string>> $children
     * @param array<string, true>         $visited
     *
     * @return \Generator<array{message: FlowMessage, depth: int}>
     */
    private function walk(array $pending, array $children, array &$visited): \Generator
    {
        while ($pending) {
            [$key, $depth] = \array_pop($pending);

            if (isset($visited[$key])) {
                continue;
            }

            $visited[$key] = true;

            yield \count($visited) - 1 => ['message' => $this->messages[$key], 'depth' => $depth];

            $next = $children[$key] ?? [];
            $nextDepth = $depth + 1;

            \array_push($pending, ...\array_map(static fn(string $child) => [$child, $nextDepth], \array_reverse($next)));
        }
    }
}
