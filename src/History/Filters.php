<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\History;

use Zenstruck\Collection;
use Zenstruck\Messenger\Monitor\Type;

final class Filters
{
    /** @var Collection<Type, int> */
    private Collection $availableMessageTypes;

    public function __construct(private readonly Storage $storage, private readonly Specification $specification)
    {
    }

    /**
     * @return Collection<Type, int>
     */
    public function availableMessageTypes(): Collection
    {
        return $this->availableMessageTypes ??= $this->storage->availableMessageTypes($this->specification)->eager()->map(
            static fn(string $type) => new Type($type)
        );
    }
}
