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

use Symfony\Component\Scheduler\Trigger\AbstractDecoratedTrigger;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;
use Zenstruck\Messenger\Monitor\Message\Type;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class TriggerInfo
{
    public function __construct(private TriggerInterface $trigger)
    {
    }

    public function get(): TriggerInterface
    {
        return $this->trigger;
    }

    public function inner(): TriggerInterface
    {
        return $this->trigger instanceof AbstractDecoratedTrigger ? $this->trigger->inner() : $this->trigger; // @phpstan-ignore-line
    }

    /**
     * @return AbstractDecoratedTrigger[]
     */
    public function decorators(): array // @phpstan-ignore-line
    {
        if ($this->trigger instanceof AbstractDecoratedTrigger) { // @phpstan-ignore-line
            return \iterator_to_array($this->trigger->decorators()); // @phpstan-ignore-line
        }

        return [];
    }

    /**
     * @return Type<AbstractDecoratedTrigger>[]
     */
    public function decoratorTypes(): array // @phpstan-ignore-line
    {
        return \array_map(static fn(AbstractDecoratedTrigger $t) => new Type($t), $this->decorators()); // @phpstan-ignore-line
    }

    /**
     * @return Type<TriggerInterface>
     */
    public function type(): Type
    {
        return new Type($this->trigger);
    }

    /**
     * @return Type<TriggerInterface>
     */
    public function innerType(): Type
    {
        return new Type($this->inner());
    }

    public function isCron(): bool
    {
        return $this->inner() instanceof CronExpressionTrigger;
    }
}
