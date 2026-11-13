<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Worker\WorkerListener;

final class WorkerListenerTest extends TestCase
{
    use ClockSensitiveTrait;

    /** @test */
    public function cooldowns_are_per_transport_and_do_not_hide_processing(): void
    {
        $clock = self::mockTime();
        $cache = new WorkerCache(new ArrayAdapter());
        $listener = new WorkerListener($cache);
        $worker = new Worker([], new MessageBus());
        $listener->onStart(new WorkerStartedEvent($worker));
        $end = $clock->now()->modify('+30 seconds');
        $listener->onCooldown('async', true, $end);
        $listener->onMessageReceived(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'healthy'));

        $workers = \iterator_to_array($cache);
        $this->assertTrue($workers[0]->isProcessing());
        $this->assertEquals($end, $workers[0]->cooldownFor('async')->endsAt());
        $this->assertNull($workers[0]->cooldownFor('healthy'));

        $clock->sleep(30);
        $this->assertNull($workers[0]->cooldownFor('async'));
    }

    /** @test */
    public function indefinite_cooldowns_can_extend_and_resume_without_a_message(): void
    {
        $clock = self::mockTime();
        $cache = new WorkerCache(new ArrayAdapter());
        $listener = new WorkerListener($cache);
        $worker = new Worker([], new MessageBus());
        $listener->onStart(new WorkerStartedEvent($worker));
        $listener->onCooldown('async', true, $clock->now()->modify('+1 second'));
        $end = $clock->now()->modify('+1 hour');
        $listener->onCooldown('async', true, $end);
        $workers = \iterator_to_array($cache);
        $this->assertEquals($end, $workers[0]->cooldownFor('async')->endsAt());

        $listener->onCooldown('async', true, null);
        $clock->sleep(3600);
        $listener->onRunning(new WorkerRunningEvent($worker, true));
        $workers = \iterator_to_array($cache);
        $this->assertNotNull($workers[0]->cooldownFor('async'));
        $this->assertNull($workers[0]->cooldownFor('async')->endsAt());
        $this->assertTrue($workers[0]->isIdle());

        $listener->onCooldown('async', false, null);
        $workers = \iterator_to_array($cache);
        $this->assertNull($workers[0]->cooldownFor('async'));
        $this->assertSame(0, $workers[0]->messagesHandled());
        $listener->onStop();
        $this->assertCount(0, \iterator_to_array($cache));
    }

    /** @test */
    public function a_running_heartbeat_does_not_count_an_unfinished_message(): void
    {
        $cache = new WorkerCache(new ArrayAdapter());
        $listener = new WorkerListener($cache);
        $worker = new Worker([], new MessageBus());
        $listener->onStart(new WorkerStartedEvent($worker));

        $listener->onRunning(new WorkerRunningEvent($worker, false));

        $workers = \iterator_to_array($cache);
        $this->assertSame(0, $workers[0]->messagesHandled());
    }

    /** @test */
    public function stays_busy_until_all_received_messages_have_finished(): void
    {
        $cache = new WorkerCache(new ArrayAdapter());
        $listener = new WorkerListener($cache);
        $worker = new Worker([], new MessageBus());
        $listener->onStart(new WorkerStartedEvent($worker));
        $first = new Envelope(new \stdClass());
        $second = new Envelope(new \stdClass());
        $listener->onMessageReceived(new WorkerMessageReceivedEvent($first, 'async'));
        $listener->onMessageReceived(new WorkerMessageReceivedEvent($second, 'async'));
        $listener->onRunning(new WorkerRunningEvent($worker, true));

        $workers = \iterator_to_array($cache);
        $this->assertTrue($workers[0]->isProcessing());
        $this->assertSame(0, $workers[0]->messagesHandled());

        $listener->onMessageFinished(new WorkerMessageHandledEvent($first, 'async'));

        $workers = \iterator_to_array($cache);
        $this->assertTrue($workers[0]->isProcessing());
        $this->assertSame(1, $workers[0]->messagesHandled());

        $listener->onMessageFinished(new WorkerMessageFailedEvent($second, 'async', new \RuntimeException()));

        $workers = \iterator_to_array($cache);
        $this->assertTrue($workers[0]->isIdle());
        $this->assertSame(2, $workers[0]->messagesHandled());
    }

    /** @test */
    public function does_not_track_a_message_that_will_not_be_handled(): void
    {
        $cache = new WorkerCache(new ArrayAdapter());
        $listener = new WorkerListener($cache);
        $worker = new Worker([], new MessageBus());
        $listener->onStart(new WorkerStartedEvent($worker));
        $received = new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async');
        $received->shouldHandle(false);
        $listener->onMessageReceived($received);

        $workers = \iterator_to_array($cache);
        $this->assertTrue($workers[0]->isIdle());
        $this->assertSame(0, $workers[0]->messagesHandled());
    }
}
