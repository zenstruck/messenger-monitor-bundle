<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Fixture\Entity;

use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage as BaseProcessedMessage;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class ProcessedMessage extends BaseProcessedMessage
{
    public function id(): string|int|null
    {
        return null;
    }
}
