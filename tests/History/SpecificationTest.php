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
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class SpecificationTest extends TestCase
{
    /**
     * @test
     */
    public function create_empty(): void
    {
        $spec = Specification::new();

        $this->assertSame(
            [
                'from' => null,
                'to' => null,
                'status' => null,
                'message_type' => null,
                'transport' => null,
                'tag' => null,
            ],
            $spec->toArray(),
        );
    }

    /**
     * @test
     */
    public function create(): void
    {
        $spec = Specification::create([]);

        $this->assertSame(
            [
                'from' => null,
                'to' => null,
                'status' => null,
                'message_type' => null,
                'transport' => null,
                'tag' => null,
            ],
            $spec->toArray(),
        );

        $spec = Specification::create([
            'from' => '2023-01-01',
            'to' => '2023-01-02',
            'status' => Specification::SUCCESS,
            'message_type' => 'foo',
            'transport' => 'bar',
            'tag' => 'baz',
        ]);

        $this->assertEquals(
            [
                'from' => new \DateTimeImmutable('2023-01-01'),
                'to' => new \DateTimeImmutable('2023-01-02'),
                'status' => Specification::SUCCESS,
                'message_type' => 'foo',
                'transport' => 'bar',
                'tag' => 'baz',
            ],
            $spec->toArray(),
        );
    }

    /**
     * @test
     */
    public function create_snapshot(): void
    {
        $spec = Specification::new();

        $this->assertSame($spec, $spec->snapshot($this->createMock(Storage::class))->specification());
    }

    /**
     * @test
     */
    public function immutable(): void
    {
        $spec = Specification::new();

        $this->assertNotSame($spec, $spec->from(null));
        $this->assertNotSame($spec, $spec->to(null));
        $this->assertNotSame($spec, $spec->on(null));
        $this->assertNotSame($spec, $spec->for(null));
        $this->assertNotSame($spec, $spec->with(null));
        $this->assertNotSame($spec, $spec->successes());
        $this->assertNotSame($spec, $spec->failures());
    }
}
