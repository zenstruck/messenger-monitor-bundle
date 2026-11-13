<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Fixture;

use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageA;

final class ParallelWorkerHandler
{
    private string $buffer = '';

    /** @return array{memory: int, process: int} */
    public function __invoke(MessageA $message): array
    {
        $clock = new MockClock('2026-11-13 12:00:00 UTC');
        Clock::set($clock);
        $this->buffer = \str_repeat('x', 64 * 1024 * 1024);
        $clock->sleep(3);
        $result = ['memory' => \memory_get_usage(true), 'process' => \getmypid()];

        if ($message->throw) {
            throw new \RuntimeException(\json_encode($result, \JSON_THROW_ON_ERROR));
        }

        return $result;
    }
}
