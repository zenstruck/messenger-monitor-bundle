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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Console\Test\InteractsWithConsole;
use Zenstruck\Messenger\Monitor\Tests\Fixture\TestService;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class ZenstruckMessengerMonitorBundleTest extends KernelTestCase
{
    use InteractsWithConsole;

    /**
     * @test
     */
    public function autowires_services(): void
    {
        /** @var TestService $service */
        $service = self::getContainer()->get(TestService::class);

        $this->assertCount(1, $service->transports);
        $this->assertCount(0, $service->workers);
        $this->assertCount(0, $service->schedules);
    }

    /**
     * @test
     */
    public function run_messenger_monitor_command(): void
    {
        $this->executeConsoleCommand('messenger:monitor')
            ->assertSuccessful()
            ->assertOutputContains('[!] No workers running.')
            ->assertOutputContains('async   n/a')
        ;
    }
}
