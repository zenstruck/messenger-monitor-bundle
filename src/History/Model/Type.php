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
 */
final class Type implements \Stringable
{
    /**
     * @param class-string $value
     */
    public function __construct(public readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function shortName(): string
    {
        return \str_contains($this->value, '\\') ? \mb_substr($this->value, \mb_strrpos($this->value, '\\') + 1) : $this->value;
    }
}
