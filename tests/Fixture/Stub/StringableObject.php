<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Fixture\Stub;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class StringableObject implements \Stringable
{
    public function __toString(): string
    {
        return 'string value';
    }
}
