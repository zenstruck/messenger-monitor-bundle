<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\History;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Messenger\Monitor\History\Period;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class PeriodTest extends TestCase
{
    use ClockSensitiveTrait;

    /**
     * @test
     */
    public function can_parse(): void
    {
        $this->assertSame(Period::YESTERDAY, Period::parse('yesterday'));
        $this->assertSame(Period::IN_LAST_DAY, Period::parse('invalid'));
        $this->assertSame(Period::YESTERDAY, Period::parse(Period::YESTERDAY));
    }

    /**
     * @test
     */
    public function can_humanize(): void
    {
        $this->assertSame('In Last Hour', Period::IN_LAST_HOUR->humanize());
    }

    /**
     * @test
     */
    public function can_get_relative_timestamps(): void
    {
        $now = self::mockTime()->now();

        $this->assertEquals(
            [$now->modify('-1 hour'), null],
            Period::IN_LAST_HOUR->timestamps(),
        );
        $this->assertEquals(
            [$now->modify('-1 day'), null],
            Period::IN_LAST_DAY->timestamps(),
        );
        $this->assertEquals(
            [$now->modify('-7 days'), null],
            Period::IN_LAST_WEEK->timestamps(),
        );
        $this->assertEquals(
            [$now->modify('-1 month'), null],
            Period::IN_LAST_MONTH->timestamps(),
        );
    }
}
