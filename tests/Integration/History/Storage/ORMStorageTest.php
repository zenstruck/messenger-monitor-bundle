<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Integration\History\Storage;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;
use Zenstruck\Messenger\Monitor\History\Model\MessageTypeMetric;
use Zenstruck\Messenger\Monitor\History\Period;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Factory\ProcessedMessageFactory;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageA;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageB;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class ORMStorageTest extends KernelTestCase
{
    use ClockSensitiveTrait, Factories, ResetDatabase;

    /**
     * @test
     */
    public function average_wait_time(): void
    {
        $start = self::mockTime()->now()->modify('-1 hour');

        ProcessedMessageFactory::createOne([
            'dispatchedAt' => $start,
            'receivedAt' => $start->modify('+20 seconds'),
        ]);
        ProcessedMessageFactory::createOne([
            'dispatchedAt' => $start,
            'receivedAt' => $start->modify('+10 seconds'),
        ]);

        $this->assertSame(15000, $this->storage()->metrics(Specification::new())['average_wait_time']);
    }

    /**
     * @test
     */
    public function average_handling_time(): void
    {
        $start = self::mockTime()->now()->modify('-1 hour');

        ProcessedMessageFactory::createOne([
            'receivedAt' => $start,
            'finishedAt' => $start->modify('+40 seconds'),
        ]);
        ProcessedMessageFactory::createOne([
            'receivedAt' => $start,
            'finishedAt' => $start->modify('+20 seconds'),
        ]);

        $this->assertSame(30000, $this->storage()->metrics(Specification::new())['average_handling_time']);
    }

    /**
     * @test
     */
    public function snapshot_counts_both_statuses_and_averages_the_selected_status(): void
    {
        $dispatchedAt = self::mockTime()->now()->modify('-1 minute');

        ProcessedMessageFactory::createOne([
            'dispatchedAt' => $dispatchedAt,
            'receivedAt' => $dispatchedAt->modify('+4 seconds'),
            'finishedAt' => $dispatchedAt->modify('+10 seconds'),
        ]);
        ProcessedMessageFactory::createOne([
            'dispatchedAt' => $dispatchedAt,
            'receivedAt' => $dispatchedAt->modify('+8 seconds'),
            'finishedAt' => $dispatchedAt->modify('+18 seconds'),
            'failureType' => \RuntimeException::class,
        ]);
        ProcessedMessageFactory::createOne(['transport' => 'other']);

        $specification = Specification::new()->on('default');
        $storage = $this->storage();

        foreach ([
            [$specification, [1, 1, 6000, 8000]],
            [$specification->successes(), [1, 1, 4000, 6000]],
            [$specification->failures(), [1, 1, 8000, 10000]],
            [$specification->on('other')->failures(), [1, 0, 0, 0]],
            [$specification->on('empty'), [0, 0, 0, 0]],
        ] as [$filter, $expected]) {
            $snapshot = $filter->snapshot($storage);

            $this->assertSame($expected, [
                $snapshot->successCount(),
                $snapshot->failureCount(),
                $snapshot->averageWaitTime(),
                $snapshot->averageHandlingTime(),
            ]);
        }
    }

    /**
     * @test
     */
    public function searches_message_history_only_for_non_blank_queries(): void
    {
        ProcessedMessageFactory::createOne([
            'type' => MessageA::class,
            'description' => 'Run APP:IMPORT',
            'tags' => 'batch_100%,orders',
            'failureMessage' => 'Connection timed out',
            'input' => ['reference' => 'invoice-42'],
            'results' => [
                ['data' => ['output' => 'Imported 42 orders']],
                ['data' => ['error_output' => 'Retrying import', 'stack_trace' => 'internal-only']],
            ],
        ]);
        ProcessedMessageFactory::createOne(['type' => MessageA::class, 'tags' => 'batchX1000', 'results' => null]);
        ProcessedMessageFactory::createOne(['type' => MessageB::class, 'description' => 'Run app:import']);

        $storage = $this->storage();

        foreach (['app:import', 'batch_100%', 'timed out', 'invoice-42', 'imported 42', 'retrying import'] as $search) {
            $specification = Specification::create(['message_type' => MessageA::class, 'search' => $search]);
            $this->assertCount(1, $storage->filter($specification), $search);
        }

        $this->assertCount(0, $storage->filter(Specification::create(['search' => 'internal-only'])));
        $this->assertCount(3, $storage->filter(Specification::create(['search' => '  '])));
    }

    /**
     * @test
     * @dataProvider tagFilters
     *
     * @param list<string|null> $storedTags
     * @param list<string>      $tags
     * @param list<string>      $notTags
     * @param list<int>         $expectedMatches
     */
    public function filters_and_purges_complete_tags(array $storedTags, array $tags, array $notTags, array $expectedMatches): void
    {
        foreach ($storedTags as $index => $value) {
            ProcessedMessageFactory::createOne(['tags' => $value, 'description' => (string) $index]);
        }

        $specification = Specification::create(['tags' => $tags, 'not_tags' => $notTags]);
        $storage = $this->storage();
        $matches = [];

        foreach ($storage->filter($specification) as $message) {
            $matches[] = (int) $message->description();
        }

        $this->assertEqualsCanonicalizing($expectedMatches, $matches);
        $this->assertSame(\count($expectedMatches), $storage->purge($specification));
        ProcessedMessageFactory::assert()->count(\count($storedTags) - \count($expectedMatches));
    }

    /** @return iterable<string, array{list<string|null>, list<string>, list<string>, list<int>}> */
    public static function tagFilters(): iterable
    {
        yield 'complete tags and descendants' => [
            ['schedule', 'schedule:default:task', 'first,schedule:default:task,last', 'unscheduled', 'reschedule', 'other:schedule', 'schedule-extra', null, ''],
            ['schedule'], [], [0, 1, 2],
        ];
        yield 'nested tags respect boundaries' => [
            ['schedule:default:task', 'schedule:default:task:child', 'schedule:default:task-extra', 'schedule:default:other', 'first,schedule:default:task,last'],
            ['schedule:default:task'], [], [0, 1, 4],
        ];
        yield 'excluded tags include untagged history' => [
            ['schedule', 'schedule:default:task', 'first,schedule,last', 'unscheduled', 'schedule-extra', null, ''],
            [], ['schedule'], [3, 4, 5, 6],
        ];
        yield 'percent underscore and escape characters are literal' => [
            ['batch_100%!', 'first,batch_100%!:child,last', 'batchX1000!', 'batch_100%other', 'batch_100%!extra', null],
            ['batch_100%!'], [], [0, 1],
        ];
        yield 'excluded wildcard characters are literal' => [
            ['batch_100%!', 'first,batch_100%!:child,last', 'batchX1000!', 'batch_100%other', 'batch_100%!extra', null],
            [], ['batch_100%!'], [2, 3, 4, 5],
        ];
        yield 'backslashes are literal' => [
            ['batch\\name', 'first,batch\\name:child,last', 'batchname', 'batch\\name-extra'],
            ['batch\\name'], [], [0, 1],
        ];
        yield 'include and exclude together' => [
            ['manual,schedule:default:task', 'manual,schedule:other:task', 'manual,unscheduled', 'schedule:default:task', null],
            ['manual', 'schedule'], ['schedule:default'], [1],
        ];
        yield 'multiple excluded tags' => [
            ['schedule:default:task', 'schedule:other:task', 'manual', 'unscheduled', null],
            [], ['schedule:default', 'manual'], [1, 3, 4],
        ];
    }

    /**
     * @test
     */
    public function per_message_type_metrics(): void
    {
        $start = self::mockTime()->now()->modify('-1 hour');

        ProcessedMessageFactory::createOne([
            'type' => MessageA::class,
            'dispatchedAt' => $start,
            'receivedAt' => $start->modify('+20 seconds'),
            'finishedAt' => $start->modify('+40 seconds'),
        ]);
        ProcessedMessageFactory::createOne([
            'type' => MessageA::class,
            'dispatchedAt' => $start,
            'receivedAt' => $start->modify('+10 seconds'),
            'finishedAt' => $start->modify('+30 seconds'),
        ]);

        ProcessedMessageFactory::createOne([
            'type' => MessageB::class,
            'dispatchedAt' => $start,
            'receivedAt' => $start->modify('+20 seconds'),
            'finishedAt' => $start->modify('+40 seconds'),
        ]);
        ProcessedMessageFactory::createOne([
            'type' => MessageB::class,
            'dispatchedAt' => $start,
            'failureType' => \Exception::class,
            'receivedAt' => $start->modify('+10 seconds'),
            'finishedAt' => $start->modify('+20 seconds'),
        ]);
        ProcessedMessageFactory::createOne([
            'type' => MessageB::class,
            'dispatchedAt' => $start,
            'receivedAt' => $start->modify('+5 seconds'),
            'finishedAt' => $start->modify('+15 seconds'),
        ]);

        /** @var array<int,MessageTypeMetric> $messageTypeMetrics */
        $messageTypeMetrics = $this->storage()
            ->perMessageTypeMetrics(Specification::create(Period::IN_LAST_HOUR))
            ->eager()
            ->sortBy(static fn(MessageTypeMetric $metric) => $metric->type()->class())
            ->values()
            ->all()
        ;

        $this->assertCount(2, $messageTypeMetrics);

        $this->assertSame(MessageA::class, $messageTypeMetrics[0]->type()->class());
        $this->assertSame(2, $messageTypeMetrics[0]->totalCount());
        $this->assertSame(0, $messageTypeMetrics[0]->failureCount());
        $this->assertSame(0.0, $messageTypeMetrics[0]->failRate());
        $this->assertSame(15000, $messageTypeMetrics[0]->averageWaitTime());
        $this->assertSame(20000, $messageTypeMetrics[0]->averageHandlingTime());
        $this->assertSame(48.0, $messageTypeMetrics[0]->handledPerDay());
        $this->assertSame(2.0, \round($messageTypeMetrics[0]->handledPerHour(), 2));
        $this->assertSame(0.03, \round($messageTypeMetrics[0]->handledPerMinute(), 2));

        $this->assertSame(MessageB::class, $messageTypeMetrics[1]->type()->class());
        $this->assertSame(3, $messageTypeMetrics[1]->totalCount());
        $this->assertSame(1, $messageTypeMetrics[1]->failureCount());
        $this->assertSame(0.33, \round($messageTypeMetrics[1]->failRate(), 2));
        $this->assertSame(11666, $messageTypeMetrics[1]->averageWaitTime());
        $this->assertSame(13333, $messageTypeMetrics[1]->averageHandlingTime());
        $this->assertSame(72.0, $messageTypeMetrics[1]->handledPerDay());
        $this->assertSame(0.05, \round($messageTypeMetrics[1]->handledPerMinute(), 2));
    }

    private function storage(): Storage
    {
        return self::getContainer()->get('zenstruck_messenger_monitor.history.storage');
    }
}
