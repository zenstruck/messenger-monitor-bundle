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
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Stub\CountableListableTransport;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Stub\CountableTransport;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Stub\ListableTransport;
use Zenstruck\Messenger\Monitor\TransportMonitor;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class TransportMonitorTest extends TestCase
{
    /**
     * @test
     */
    public function can_count(): void
    {
        $monitor = $this->create();

        $this->assertCount(4, $monitor);
        $this->assertCount(2, $monitor->countable());
        $this->assertCount(2, $monitor->listable());
    }

    /**
     * @test
     */
    public function can_get(): void
    {
        $info = $this->create()->get('second');

        $this->assertSame('second', $info->name());
        $this->assertInstanceOf(CountableTransport::class, $info->transport());
    }

    /**
     * @test
     */
    public function invalid_get(): void
    {
        $monitor = $this->create();

        $this->expectException(\InvalidArgumentException::class);

        $monitor->get('invalid');
    }

    /**
     * @test
     */
    public function can_iterate(): void
    {
        $infos = \iterator_to_array($this->create());

        $this->assertCount(4, $infos);
        $this->assertInstanceOf(TransportInterface::class, $infos['first']->transport());
        $this->assertSame(CountableTransport::class, $infos['second']->transport()::class);
        $this->assertSame(ListableTransport::class, $infos['third']->transport()::class);
        $this->assertSame(CountableListableTransport::class, $infos['fourth']->transport()::class);
    }

    private function create(): TransportMonitor
    {
        return new TransportMonitor(new ServiceLocator([
            'first' => fn() => $this->createMock(TransportInterface::class),
            'second' => fn() => new CountableTransport(),
            'third' => fn() => new ListableTransport(),
            'fourth' => fn() => new CountableListableTransport(),
        ]));
    }
}
