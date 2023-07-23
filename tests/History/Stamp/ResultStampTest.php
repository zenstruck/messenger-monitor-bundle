<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\History\Stamp;

use PHPUnit\Framework\TestCase;
use Zenstruck\Messenger\Monitor\History\Stamp\ResultStamp;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class ResultStampTest extends TestCase
{
    /**
     * @test
     */
    public function normalizes_values(): void
    {
        $stamp = new ResultStamp($this->rawValues());

        $this->assertSame($this->normalizedValues(), $stamp->value);
    }

    private function rawValues(): array
    {
        try {
            return [
                'nested1' => [
                    'nested2' => [
                        'datetime' => new \DateTime('2021-01-04 11:22:13 America/New_York'),
                        'int' => 56,
                        'array' => ['value1', 'value2', fn($a) => $a],
                        'resource' => $resource = \fopen(__FILE__, 'r'),
                        'nested3' => [
                            'foo' => 'bar',
                        ],
                    ],
                    'float' => 65.6,
                    'function' => fn($a) => $a,
                    'object1' => new \stdClass(),
                ],
                'string' => 'value',
                'null' => null,
            ];
        } finally {
            \fclose($resource);
        }
    }

    private function normalizedValues(): array
    {
        return [
            'nested1' => [
                'nested2' => [
                    'datetime' => '2021-01-04T11:22:13-05:00',
                    'int' => 56,
                    'array' => ['value1', 'value2', 'Closure'],
                    'resource' => 'resource (closed)',
                    'nested3' => [
                        'foo' => 'bar',
                    ],
                ],
                'float' => 65.6,
                'function' => 'Closure',
                'object1' => \stdClass::class,
            ],
            'string' => 'value',
            'null' => null,
        ];
    }
}
