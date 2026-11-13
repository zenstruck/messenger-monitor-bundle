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
use Zenstruck\Messenger\Monitor\History\Model\Flow;

final class FlowTest extends TestCase
{
    /** @test */
    public function orders_a_chain_by_causation_instead_of_recording_order(): void
    {
        $flow = new Flow([
            self::attempt('last', 'middle'),
            self::attempt('middle', 'first'),
            self::attempt('first'),
        ]);

        $this->assertSame(['first', 'middle', 'last'], self::ids($flow));
        $this->assertSame([0, 1, 2], \array_column(\iterator_to_array($flow), 'depth'));
    }

    /** @test */
    public function groups_retries_and_nests_parallel_branches(): void
    {
        $failed = self::attempt('left', 'root', 1);
        $failed['failureType'] = \RuntimeException::class;
        $retried = self::attempt('left', 'root', 2);
        $flow = new Flow([
            self::attempt('root'),
            $retried,
            self::attempt('right', 'root'),
            $failed,
            self::attempt('right-child', 'right'),
        ]);

        $this->assertCount(4, $flow);
        $this->assertSame(['root', 'left', 'right', 'right-child'], self::ids($flow));
        $rows = \iterator_to_array($flow);
        $this->assertSame([0, 1, 1, 2], \array_column($rows, 'depth'));
        $left = $rows[1]['message'];
        $this->assertCount(2, $left);
        $this->assertSame([1, 2], \array_column(\iterator_to_array($left), 'attempt'));
        $this->assertNull($left->latest()['failureType']);
    }

    /** @test */
    public function keeps_missing_parents_and_cycles_visible_without_duplicates(): void
    {
        $flow = new Flow([
            self::attempt('orphan', 'purged'),
            self::attempt('self', 'self'),
            self::attempt('a', 'b'),
            self::attempt('b', 'a'),
            self::attempt('0'),
        ]);

        $this->assertCount(5, $flow);
        $this->assertSame(['orphan', 'self', '0', 'a', 'b'], self::ids($flow));
        $this->assertSame(self::ids($flow), self::ids($flow));
    }

    /** @test */
    public function traverses_long_flows_without_recursive_calls(): void
    {
        $attempts = [];

        for ($index = 0; $index < 2500; ++$index) {
            $attempts[] = self::attempt((string) $index, $index ? (string) ($index - 1) : null);
        }

        $flow = new Flow(\array_reverse($attempts));

        $this->assertSame(\array_map('strval', \range(0, 2499)), self::ids($flow));
    }

    /** @return array<string, mixed> */
    public static function attempt(string $messageId, ?string $parent = null, int $attempt = 1): array
    {
        $time = new \DateTimeImmutable('2026-11-13 12:00:00 UTC');

        return [
            'id' => $messageId.'-'.$attempt,
            'messageId' => $messageId,
            'causationId' => $parent,
            'type' => \stdClass::class,
            'description' => null,
            'transport' => 'async',
            'attempt' => $attempt,
            'failureType' => null,
            'dispatchedAt' => $time,
            'receivedAt' => $time->modify('+'.$attempt.' seconds'),
            'finishedAt' => $time->modify('+'.($attempt + 1).' seconds'),
            'handleTime' => 1000,
        ];
    }

    /** @return list<string> */
    private static function ids(Flow $flow): array
    {
        return \array_map(static fn(array $row) => $row['message']->messageId(), \iterator_to_array($flow));
    }
}
