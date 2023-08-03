<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Schedule;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Zenstruck\Messenger\Monitor\Message\Type;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class MessageInfo
{
    public function __construct(private object $object)
    {
    }

    public function get(): object
    {
        return $this->object;
    }

    public function isRedispatch(): bool
    {
        return $this->object instanceof RedispatchMessage;
    }

    /**
     * @return string[]
     */
    public function redispatchTransports(): array
    {
        if ($this->object instanceof RedispatchMessage) {
            return (array) $this->object->transportNames;
        }

        return [];
    }

    /**
     * @return Type<object>
     */
    public function type(): Type
    {
        return new Type(self::unwrap($this->object));
    }

    private static function unwrap(object $message): object
    {
        if ($message instanceof Envelope) {
            $message = $message->getMessage();
        }

        if ($message instanceof RedispatchMessage) {
            return self::unwrap($message->envelope);
        }

        return $message;
    }
}
