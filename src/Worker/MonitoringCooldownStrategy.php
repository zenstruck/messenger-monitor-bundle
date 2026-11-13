<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Worker;

use Symfony\Component\Messenger\Cooldown\CooldownStatus;
use Symfony\Component\Messenger\Cooldown\CooldownStrategyInterface;
use Symfony\Component\Messenger\Envelope;

/** PHPStan cannot ignore an unknown implemented interface. */
if (!\interface_exists(CooldownStrategyInterface::class)) {
    return;
}

/** @internal */
final class MonitoringCooldownStrategy implements CooldownStrategyInterface
{
    public function __construct(private CooldownStrategyInterface $strategy, private WorkerListener $listener) // @phpstan-ignore class.notFound, class.notFound (Symfony 8.2)
    {
    }

    public function checkStatus(string $transportName): CooldownStatus // @phpstan-ignore class.notFound (Symfony 8.2)
    {
        $status = $this->strategy->checkStatus($transportName); // @phpstan-ignore class.notFound (Symfony 8.2)
        $this->listener->onCooldown($transportName, $status->isCoolingDown(), $status->getEnd());

        return $status;
    }

    public function recordSuccess(string $transportName, Envelope $envelope): void
    {
        $this->strategy->recordSuccess($transportName, $envelope); // @phpstan-ignore class.notFound (Symfony 8.2)
    }

    public function recordFailure(string $transportName, ?Envelope $envelope, \Throwable $throwable): void
    {
        $this->strategy->recordFailure($transportName, $envelope, $throwable); // @phpstan-ignore class.notFound (Symfony 8.2)
    }
}
