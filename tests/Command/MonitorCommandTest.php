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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;
use Zenstruck\Console\Test\InteractsWithConsole;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class MonitorCommandTest extends KernelTestCase
{
    use InteractsWithConsole;

    /**
     * @test
     */
    public function no_workers(): void
    {
        self::getContainer()->get('cache.app')->clear();

        $this->executeConsoleCommand('messenger:monitor')
            ->assertSuccessful()
            ->assertOutputContains('No workers running.')
            ->output()
        ;
    }

    /**
     * @test
     */
    public function worker_running(): void
    {
        self::getContainer()->get('cache.app')->clear();

        $worker = new Worker([
            'first' => $this->createMock(ReceiverInterface::class),
            'second' => $this->createMock(ReceiverInterface::class),
        ], $this->createMock(MessageBusInterface::class));

        self::getContainer()->get('event_dispatcher')->dispatch(new WorkerStartedEvent($worker));

        $this->executeConsoleCommand('messenger:monitor')
            ->assertSuccessful()
            ->assertOutputContains('idle     < 1 sec   first, second   n/a')
            ->output()
        ;
    }
}
