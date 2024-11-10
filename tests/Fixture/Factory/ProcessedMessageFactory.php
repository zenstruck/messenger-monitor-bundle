<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Fixture\Factory;

use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Entity\ProcessedMessage;

/**
 * @extends PersistentObjectFactory<ProcessedMessage>
 */
final class ProcessedMessageFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ProcessedMessage::class;
    }

    protected function initialize(): static
    {
        return parent::initialize()
            ->instantiateWith(Instantiator::withoutConstructor()->alwaysForce())
            ->beforeInstantiate(function(array $attributes) {
                if (!isset($attributes['waitTime'])) {
                    $attributes['waitTime'] = \max(0, $attributes['receivedAt']->getTimestamp() - $attributes['dispatchedAt']->getTimestamp());
                }

                if (!isset($attributes['processingTime'])) {
                    $attributes['handleTime'] = \max(0, $attributes['finishedAt']->getTimestamp() - $attributes['receivedAt']->getTimestamp());
                }

                return $attributes;
            })
        ;
    }

    protected function defaults(): array|callable
    {
        $dispatchedAt = \DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-1 year', '-1 minute'));
        $receivedAt = $dispatchedAt->modify('+'.self::faker()->numberBetween(1, 30).' seconds');
        $finishedAt = $receivedAt->modify('+'.self::faker()->numberBetween(1, 29).' seconds');

        return [
            'runId' => self::faker()->randomNumber(),
            'type' => \stdClass::class,
            'description' => null,
            'transport' => 'default',
            'dispatchedAt' => $dispatchedAt,
            'receivedAt' => $receivedAt,
            'finishedAt' => $finishedAt,
            'tags' => null,
            'memoryUsage' => self::faker()->randomNumber(),
            'results' => [],
        ];
    }
}
