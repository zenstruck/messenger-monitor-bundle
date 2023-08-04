<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class DescriptionStamp implements StampInterface
{
    public function __construct(
        public readonly string $value,
    ) {
    }
}
