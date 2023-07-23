<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\History\Model;

use PHPUnit\Framework\TestCase;
use Zenstruck\Messenger\Monitor\History\Model\Type;
use Zenstruck\Messenger\Monitor\History\Storage;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class TypeTest extends TestCase
{
    /**
     * @test
     */
    public function constructor(): void
    {
        $this->assertSame(Storage::class, (string) new Type(Storage::class));
        $this->assertSame('foo', (string) new Type('foo'));
    }

    /**
     * @test
     */
    public function short_name(): void
    {
        $this->assertSame('Storage', (new Type(Storage::class))->shortName());
        $this->assertSame('stdClass', (new Type(\stdClass::class))->shortName());
        $this->assertSame('foo', (new Type('foo'))->shortName());
        $this->assertSame('foo', (new Type('\\foo'))->shortName());
    }
}
