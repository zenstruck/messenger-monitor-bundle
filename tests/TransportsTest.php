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
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Stub\CountableListableTransport;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Stub\CountableTransport;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Stub\ListableTransport;
use Zenstruck\Messenger\Monitor\Transports;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Workers;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class TransportsTest extends TestCase
{
    /**
     * @test
     */
    public function can_count(): void
    {
        $monitor = $this->create();

        $this->assertCount(5, $monitor);
        $this->assertCount(2, $monitor->filter()->countable());
        $this->assertCount(2, $monitor->filter()->listable());
    }

    /**
     * @test
     */
    public function can_get(): void
    {
        $info = $this->create()->get('second');

        $this->assertSame('second', $info->name());
        $this->assertInstanceOf(CountableTransport::class, $info->get());
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

        $this->assertCount(5, $infos);
        $this->assertInstanceOf(TransportInterface::class, $infos['first']->get());
        $this->assertSame(CountableTransport::class, $infos['second']->get()::class);
        $this->assertSame(ListableTransport::class, $infos['third']->get()::class);
        $this->assertSame(CountableListableTransport::class, $infos['fourth']->get()::class);
    }

    private function create(): Transports
    {
        return new Transports(
            new ServiceLocator([
                'first' => fn() => $this->createMock(TransportInterface::class),
                'second' => fn() => new CountableTransport(),
                'third' => fn() => new ListableTransport(),
                'fourth' => fn() => new CountableListableTransport(),
                'fifth' => fn() => new SyncTransport($this->createMock(MessageBusInterface::class)),
            ]),
            new Workers(new WorkerCache(new NullAdapter()))
        );
    }
}
