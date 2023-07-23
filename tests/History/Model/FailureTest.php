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
use Zenstruck\Messenger\Monitor\History\Model\Failure;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class FailureTest extends TestCase
{
    /**
     * @test
     */
    public function constructor(): void
    {
        $this->assertSame('foo: bar', (string) new Failure('foo: bar'));
        $this->assertSame('RuntimeException: message', (string) new Failure(new \RuntimeException('message')));
    }
}
