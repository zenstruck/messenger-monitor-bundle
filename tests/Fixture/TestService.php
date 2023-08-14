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

use Zenstruck\Messenger\Monitor\Schedules;
use Zenstruck\Messenger\Monitor\TransportMonitor;
use Zenstruck\Messenger\Monitor\Workers;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class TestService
{
    public function __construct(
        public readonly TransportMonitor $transportMonitor,
        public readonly Workers $workers,
        public readonly Schedules $schedules,
    ) {
    }
}
