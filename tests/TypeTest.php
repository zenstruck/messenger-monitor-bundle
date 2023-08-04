<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests;

use PHPUnit\Framework\TestCase;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Type;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class TypeTest extends TestCase
{
    /**
     * @test
     */
    public function create_from_string(): void
    {
        $type = new Type(Storage::class);

        $this->assertSame(Storage::class, $type->class());
        $this->assertSame(Storage::class, (string) $type);
        $this->assertNull($type->object());
        $this->assertNull($type->description());
    }

    /**
     * @test
     */
    public function create_from_object(): void
    {
        $type = new Type($obj = new \stdClass());

        $this->assertSame(\stdClass::class, $type->class());
        $this->assertSame(\stdClass::class, (string) $type);
        $this->assertSame($obj, $type->object());
        $this->assertNull($type->description());
    }

    /**
     * @test
     */
    public function description(): void
    {
        $type = new Type(new class() {
            public function __toString(): string
            {
                return 'foo';
            }
        });

        $this->assertSame('foo', $type->description());
    }

    /**
     * @test
     */
    public function short_name(): void
    {
        $this->assertSame('Storage', (new Type(Storage::class))->shortName());
        $this->assertSame('stdClass', (new Type(\stdClass::class))->shortName());
        $this->assertSame('stdClass', (new Type(new \stdClass()))->shortName());
        $this->assertSame('foo', (new Type('foo'))->shortName());
        $this->assertSame('foo', (new Type('\\foo'))->shortName());
    }
}
