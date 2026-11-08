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
