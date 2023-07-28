<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor;

use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Zenstruck\Messenger\Monitor\History\Snapshot;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Schedule\ScheduleInfo;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @implements \IteratorAggregate<ScheduleInfo>
 *
 * @phpstan-import-type Input from Specification
 */
final class ScheduleMonitor implements \IteratorAggregate, \Countable
{
    /**
     * @internal
     *
     * @param ServiceProviderInterface<ScheduleProviderInterface> $schedules
     */
    public function __construct(
        private ServiceProviderInterface $schedules,
        private TransportMonitor $transports,
        private ?Storage $storage = null
    ) {
    }

    public function get(?string $name = null): ScheduleInfo
    {
        if (!$name) {
            $name = (string) \array_key_first($this->schedules->getProvidedServices());
        }

        if (!$this->schedules->has($name)) {
            throw new \InvalidArgumentException(\sprintf('Schedule "%s" does not exist.', $name));
        }

        return new ScheduleInfo($name, $this->schedules->get($name), $this->transports, $this->storage);
    }

    /**
     * @param Specification|Input|null $specification
     */
    public function history(Specification|array|null $specification = null): Snapshot
    {
        return Specification::create($specification)
            ->with('schedule')
            ->snapshot($this->storage ?? throw new \LogicException('No history storage configured.'))
        ;
    }

    public function getIterator(): \Traversable
    {
        foreach ($this->schedules->getProvidedServices() as $name => $schedule) {
            yield $this->get($name);
        }
    }

    public function count(): int
    {
        return \count($this->schedules->getProvidedServices());
    }
}
