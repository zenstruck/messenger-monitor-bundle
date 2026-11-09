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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Zenstruck\Console\Test\InteractsWithConsole;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;
use Zenstruck\Messenger\Monitor\Schedules;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Factory\ProcessedMessageFactory;
use Zenstruck\Messenger\Monitor\Transports;

final class PurgeCommandTest extends KernelTestCase
{
    use Factories, InteractsWithConsole, ResetDatabase;

    /**
     * @test
     * @dataProvider invalidStatuses
     */
    public function rejects_invalid_status_without_deleting_history(string $status): void
    {
        ProcessedMessageFactory::createMany(2);

        $command = $this->consoleCommand('messenger:monitor:purge --older-than=all');
        $command->addOption('status', $status);
        $result = $command->execute();

        $result->assertStatusCode(Command::INVALID);
        $result->assertOutputContains('Invalid status');
        ProcessedMessageFactory::assert()->count(2);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidStatuses(): iterable
    {
        yield 'misspelled' => ['fialed'];
        yield 'empty' => [''];
        yield 'wrong case' => ['FAILED'];
        yield 'unsupported all' => ['all'];
    }

    /**
     * @test
     * @dataProvider validStatuses
     */
    public function purges_only_the_requested_status(?string $status, int $remaining): void
    {
        ProcessedMessageFactory::createOne();
        ProcessedMessageFactory::createOne(['failureType' => \RuntimeException::class]);

        $command = $this->consoleCommand('messenger:monitor:purge --older-than=all');

        if (null !== $status) {
            $command->addOption('status', $status);
        }

        $result = $command->execute();
        $result->assertSuccessful();
        ProcessedMessageFactory::assert()->count($remaining);
    }

    /** @return iterable<string, array{?string, int}> */
    public static function validStatuses(): iterable
    {
        yield 'success' => ['success', 1];
        yield 'failed' => ['failed', 1];
        yield 'omitted' => [null, 0];
    }

    /** @test */
    public function excludes_schedule_tags_from_a_general_purge(): void
    {
        ProcessedMessageFactory::createOne(['tags' => 'schedule:default:task']);
        ProcessedMessageFactory::createOne(['tags' => 'manual,schedule,priority']);
        ProcessedMessageFactory::createOne(['tags' => 'unscheduled']);
        ProcessedMessageFactory::createOne(['tags' => null]);

        $result = $this->executeConsoleCommand('messenger:monitor:purge --older-than=all --exclude-schedules');

        $result->assertSuccessful();
        $result->assertOutputContains('Purged 2 processed messages.');
        ProcessedMessageFactory::assert()->count(2);
        ProcessedMessageFactory::assert()->exists(['tags' => 'schedule:default:task']);
        ProcessedMessageFactory::assert()->exists(['tags' => 'manual,schedule,priority']);
    }

    /** @test */
    public function removes_only_orphaned_schedule_history(): void
    {
        $task = RecurringMessage::every('1 hour', new \stdClass());
        $taskId = $task->getId();
        $schedule = new Schedule();
        $schedule->add($task);
        $provider = $this->createMock(ScheduleProviderInterface::class);
        $provider->method('getSchedule')->willReturn($schedule);
        $container = self::getContainer();
        $schedules = new Schedules(
            new ServiceLocator(['billing_jobs' => static fn() => $provider]),
            $container->get(Transports::class),
        );
        $container->set('zenstruck_messenger_monitor.schedules', $schedules);

        ProcessedMessageFactory::createOne(['tags' => 'manual,schedule:billing_jobs:'.$taskId.',priority']);
        ProcessedMessageFactory::createOne(['tags' => 'schedule:billingXjobs:'.$taskId]);
        ProcessedMessageFactory::createOne(['tags' => 'schedule:removed:task']);
        ProcessedMessageFactory::createOne(['tags' => 'unscheduled']);
        ProcessedMessageFactory::createOne(['tags' => null]);

        $result = $this->executeConsoleCommand('messenger:monitor:schedule:purge --remove-orphans');

        $result->assertSuccessful();
        $result->assertOutputContains('Removed 2 orphaned task histories');
        ProcessedMessageFactory::assert()->count(3);
        ProcessedMessageFactory::assert()->exists(['tags' => 'manual,schedule:billing_jobs:'.$taskId.',priority']);
        ProcessedMessageFactory::assert()->exists(['tags' => 'unscheduled']);
        ProcessedMessageFactory::assert()->exists(['tags' => null]);
    }
}
