<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;
use Zenstruck\Console\Test\TestCommand;
use Zenstruck\Messenger\Monitor\Command\MonitorCommand;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Stub\CountableListableTransport;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Stub\CountableTransport;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Stub\ListableTransport;
use Zenstruck\Messenger\Monitor\TransportMonitor;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Worker\WorkerListener;
use Zenstruck\Messenger\Monitor\Workers;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class MonitorCommandTest extends TestCase
{
    /**
     * @test
     */
    public function no_workers_or_transports(): void
    {
        $command = new MonitorCommand(
            new Workers(new WorkerCache(new NullAdapter())),
            new TransportMonitor(new ServiceLocator([]), $this->workers())
        );

        TestCommand::for($command)
            ->execute()
            ->assertSuccessful()
            ->assertOutputContains('[!] No workers running.')
            ->assertOutputContains('[!] No transports configured.')
        ;
    }

    /**
     * @test
     */
    public function shows_workers_and_transports(): void
    {
        $cache = new WorkerCache(new ArrayAdapter());
        $listener1 = new WorkerListener($cache);
        $listener2 = new WorkerListener($cache);
        $worker2 = new Worker([
            'first' => $this->createMock(ReceiverInterface::class),
            'third' => $this->createMock(ReceiverInterface::class),
        ], $this->createMock(MessageBusInterface::class));
        $worker2->getMetadata()->set(['queueNames' => ['q2']]);
        $listener1->onStart(new WorkerStartedEvent(new Worker([
            'first' => $this->createMock(ReceiverInterface::class),
            'second' => $this->createMock(ReceiverInterface::class),
        ], $this->createMock(MessageBusInterface::class))));
        $listener2->onStart(new WorkerStartedEvent($worker2));

        $command = new MonitorCommand(
            new Workers($cache),
            new TransportMonitor(new ServiceLocator([
                'first' => fn() => $this->createMock(TransportInterface::class),
                'second' => fn() => new CountableTransport(),
                'third' => fn() => new ListableTransport(),
                'fourth' => fn() => new CountableListableTransport(),
            ]), $this->workers())
        );

        TestCommand::for($command)
            ->execute()
            ->assertSuccessful()
            ->assertOutputContains('idle     < 1 sec   first, second   n/a')
            ->assertOutputContains('idle     < 1 sec   first, third    q2')
            ->assertOutputContains('first    n/a               0')
            ->assertOutputContains('second   0                 0')
            ->assertOutputContains('third    n/a               0')
            ->assertOutputContains('fourth   0                 0')
        ;
    }

    private function workers(): Workers
    {
        return new Workers(new WorkerCache(new NullAdapter()));
    }
}
