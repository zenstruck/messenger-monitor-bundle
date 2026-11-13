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
    /** @var array<class-string, array<class-string, list<\ReflectionAttribute<static>>>> */
    private static array $attributes = [];

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

        foreach (self::attributesFor($envelope->getMessage()::class) as $attribute) {
            yield $attribute->newInstance();
        }
    }

    /**
     * @internal
     */
    final public static function firstFrom(Envelope $envelope): ?static
    {
        if ($stamp = $envelope->all(static::class)[0] ?? null) {
            return $stamp;
        }

        $attributes = self::attributesFor($envelope->getMessage()::class);

        return isset($attributes[0]) ? $attributes[0]->newInstance() : null;
    }

    /**
     * @param class-string $messageClass
     *
     * @return list<\ReflectionAttribute<static>>
     */
    private static function attributesFor(string $messageClass): array
    {
        return self::$attributes[static::class][$messageClass] ??= self::reflectAttributes($messageClass);
    }

    /**
     * @param class-string $messageClass
     *
     * @return list<\ReflectionAttribute<static>>
     */
    private static function reflectAttributes(string $messageClass): array
    {
        $original = $reflection = new \ReflectionClass($messageClass);
        $attributes = [];

        while (false !== $reflection) {
            \array_push($attributes, ...$reflection->getAttributes(static::class));
            $reflection = $reflection->getParentClass();
        }

        foreach ($original->getInterfaces() as $refInterface) {
            \array_push($attributes, ...$refInterface->getAttributes(static::class));
        }

        return $attributes;
    }
}
