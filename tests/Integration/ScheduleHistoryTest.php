<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Scheduler\Schedule;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Schedules;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Factory\ProcessedMessageFactory;
use Zenstruck\Messenger\Monitor\Transports;

final class ScheduleHistoryTest extends KernelTestCase
{
    use Factories, ResetDatabase;

    /** @test */
    public function history_is_scoped_to_the_schedule_and_preserves_other_filters(): void
    {
        $expected = ProcessedMessageFactory::createOne(['tags' => 'schedule:billing_jobs:first', 'transport' => 'async']);
        ProcessedMessageFactory::createOne(['tags' => 'manual,schedule:billing_jobs:second', 'transport' => 'other']);
        ProcessedMessageFactory::createOne(['tags' => 'schedule:billingXjobs:first']);
        ProcessedMessageFactory::createOne(['tags' => 'schedule:billing_jobs-extra:first']);
        ProcessedMessageFactory::createOne(['tags' => 'schedulebilling_jobs:first']);
        ProcessedMessageFactory::createOne(['tags' => null]);

        $container = self::getContainer();
        $schedules = new Schedules(
            new ServiceLocator(['billing_jobs' => static fn() => new Schedule()]),
            $container->get(Transports::class),
            $container->get(Storage::class),
        );
        $schedule = $schedules->get('billing_jobs');
        $history = $schedule->history();

        $this->assertSame(2, $history->totalCount());
        $this->assertCount(2, $history->messages());

        $filteredHistory = $schedule->history(['transport' => 'async']);
        $messages = $filteredHistory->messages();
        $message = $messages->first();

        $this->assertSame(1, $filteredHistory->totalCount());
        $this->assertCount(1, $messages);
        $this->assertNotNull($message);
        $expectedId = $expected->id();
        $this->assertSame($expectedId, $message->id());
    }
}
