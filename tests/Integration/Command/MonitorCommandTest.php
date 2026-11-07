<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Console\Test\InteractsWithConsole;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class MonitorCommandTest extends KernelTestCase
{
    use InteractsWithConsole, ResetDatabase;

    /**
     * @test
     */
    public function run_messenger_monitor_command(): void
    {
        $this->executeConsoleCommand('messenger:monitor')
            ->assertSuccessful()
            ->assertOutputContains('[!] No workers running.')
            ->assertOutputContains('async   0                 0')
        ;
    }

    /**
     * @test
     */
    public function run_messenger_monitor_command_for_all_time_with_an_empty_history(): void
    {
        $this->executeConsoleCommand('messenger:monitor --period=all')
            ->assertSuccessful()
            ->assertOutputContains('Handled Per Minute')
        ;
    }
}
