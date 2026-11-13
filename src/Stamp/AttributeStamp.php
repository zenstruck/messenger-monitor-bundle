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

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
abstract class AttributeStamp implements StampInterface
{
    /**
     * @internal
     *
     * @return static[]
     */
    final public static function from(Envelope $envelope): iterable
    {
        foreach ($envelope->all(static::class) as $stamp) {
            yield $stamp;
        }

        $original = $reflection = new \ReflectionClass($envelope->getMessage());

        while (false !== $reflection) {
            foreach ($reflection->getAttributes(static::class) as $attribute) {
                yield $attribute->newInstance();
            }

            $reflection = $reflection->getParentClass();
        }

        foreach ($original->getInterfaces() as $refInterface) {
            foreach ($refInterface->getAttributes(static::class) as $attribute) {
                yield $attribute->newInstance();
            }
        }
    }

    /**
     * @internal
     */
    final public static function firstFrom(Envelope $envelope): ?static
    {
        foreach (self::from($envelope) as $stamp) {
            return $stamp;
        }

        return null;
    }
}
