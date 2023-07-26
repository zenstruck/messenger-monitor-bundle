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
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Worker\WorkerInfo;
use Zenstruck\Messenger\Monitor\Worker\WorkerListener;
use Zenstruck\Messenger\Monitor\WorkerMonitor;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class WorkerMonitorTest extends TestCase
{
    private WorkerCache $cache;

    protected function setUp(): void
    {
        $this->cache = new WorkerCache(new ArrayAdapter());
    }

    /**
     * @test
     */
    public function no_workers(): void
    {
        $monitor = new WorkerMonitor($this->cache);

        $this->assertFalse($monitor->isRunning());
        $this->assertCount(0, $monitor);
        $this->assertEmpty($monitor);
        $this->assertEmpty($monitor->all());
        $this->assertEmpty($monitor->forQueue('foo'));
        $this->assertEmpty($monitor->forTransport('foo'));
    }

    /**
     * @test
     */
    public function get_worker_infos(): void
    {
        $monitor = new WorkerMonitor($this->cache);
        $listener1 = new WorkerListener($this->cache);
        $listener2 = new WorkerListener($this->cache);
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

        $this->assertCount(2, $monitor);
        $this->assertCount(2, $monitor->forTransport('first'));
        $this->assertCount(1, $monitor->forTransport('second'));
        $this->assertCount(1, $monitor->forTransport('third'));
        $this->assertCount(0, $monitor->forQueue('q1'));
        $this->assertCount(1, $monitor->forQueue('q2'));

        $listener2->onStop();

        $this->assertCount(1, $monitor);
        $this->assertCount(1, $monitor->forTransport('first'));
        $this->assertCount(1, $monitor->forTransport('second'));
        $this->assertCount(0, $monitor->forTransport('third'));
        $this->assertCount(0, $monitor->forQueue('q1'));
        $this->assertCount(0, $monitor->forQueue('q2'));

        $listener1->onStop();

        $this->assertCount(0, $monitor);
        $this->assertCount(0, $monitor->forTransport('first'));
        $this->assertCount(0, $monitor->forTransport('second'));
        $this->assertCount(0, $monitor->forTransport('third'));
        $this->assertCount(0, $monitor->forQueue('q1'));
        $this->assertCount(0, $monitor->forQueue('q2'));
    }

    /**
     * @test
     */
    public function get_worker_status(): void
    {
        $monitor = new WorkerMonitor($this->cache);
        $listener = new WorkerListener($this->cache);
        $worker = new Worker([
            'first' => $this->createMock(ReceiverInterface::class),
            'second' => $this->createMock(ReceiverInterface::class),
        ], $this->createMock(MessageBusInterface::class));

        $listener->onStart(new WorkerStartedEvent($worker));

        $this->assertSame(WorkerInfo::IDLE, $monitor->all()[0]->status());
        $this->assertSame(0, $monitor->all()[0]->messagesHandled());
        $this->assertTrue($monitor->all()[0]->memoryUsage()->isGreaterThan(1));

        $listener->onRunning(new WorkerRunningEvent($worker, isWorkerIdle: false));

        $this->assertSame(WorkerInfo::PROCESSING, $monitor->all()[0]->status());
        $this->assertSame(1, $monitor->all()[0]->messagesHandled());
        $this->assertTrue($monitor->all()[0]->memoryUsage()->isGreaterThan(1));

        $listener->onRunning(new WorkerRunningEvent($worker, isWorkerIdle: true));

        $this->assertSame(WorkerInfo::IDLE, $monitor->all()[0]->status());
        $this->assertSame(1, $monitor->all()[0]->messagesHandled());
        $this->assertTrue($monitor->all()[0]->memoryUsage()->isGreaterThan(1));
    }
}
