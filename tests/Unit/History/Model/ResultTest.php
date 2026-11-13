<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Unit\History\Model;

use PHPUnit\Framework\TestCase;
use Zenstruck\Messenger\Monitor\History\Model\Result;

final class ResultTest extends TestCase
{
    /** @test */
    public function legacy_results_have_no_handler_measurements(): void
    {
        $result = new Result(['data' => ['duration_ms' => 12, 'memory_change' => 42]]);

        $this->assertNull($result->duration());
        $this->assertNull($result->memoryChange());
        $this->assertSame(['duration_ms' => 12, 'memory_change' => 42], $result->data());
    }

    /** @test */
    public function preserves_zero_duration_and_signed_memory_changes(): void
    {
        foreach ([-1048576, 0, 1048576] as $change) {
            $result = new Result(['data' => [], 'duration_ms' => 0, 'memory_change' => $change]);

            $this->assertSame(0, $result->duration());
            $this->assertSame($change, $result->memoryChange());
        }
    }
}
