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

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * @internal
 */
final class StoredInputStamp implements NonSendableStampInterface
{
    /** @param array<array-key, mixed>|null $input */
    public function __construct(public readonly ?array $input)
    {
    }
}
