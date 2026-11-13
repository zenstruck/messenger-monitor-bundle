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
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Entity\ProcessedMessage;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Factory\ProcessedMessageFactory;

final class FlowStorageTest extends KernelTestCase
{
    use Factories, ResetDatabase;

    /** @test */
    public function fetches_the_entire_flow_once_without_loading_history_payloads(): void
    {
        $old = new \DateTimeImmutable('2020-01-01');
        ProcessedMessageFactory::createOne(['messageId' => 'end', 'correlationId' => 'flow', 'causationId' => 'middle']);
        ProcessedMessageFactory::createOne(['messageId' => 'middle', 'correlationId' => 'flow', 'causationId' => 'start', 'attempt' => 2]);
        ProcessedMessageFactory::createOne(['messageId' => 'middle', 'correlationId' => 'flow', 'causationId' => 'start', 'attempt' => 1, 'failureType' => \RuntimeException::class]);
        ProcessedMessageFactory::createOne([
            'messageId' => 'start', 'correlationId' => 'flow', 'dispatchedAt' => $old,
            'input' => ['large' => \str_repeat('x', 10000)],
            'results' => [['data' => ['output' => \str_repeat('x', 10000)]]],
        ]);
        ProcessedMessageFactory::createOne(['messageId' => 'unrelated', 'correlationId' => 'other']);
        ProcessedMessageFactory::createOne(['correlationId' => 'flow']);

        $container = self::getContainer();
        $manager = $container->get('doctrine')->getManager();
        $manager->clear();
        $storage = $container->get(Storage::class);
        $holder = $container->get('doctrine.debug_data_holder');
        $holder->reset();
        $flow = $storage->flow('flow');
        $rows = \iterator_to_array($flow);

        $this->assertCount(3, $flow);
        $this->assertSame(['start', 'middle', 'end'], \array_map(static fn(array $row) => $row['message']->messageId(), $rows));
        $this->assertCount(2, $rows[1]['message']);
        $queries = $holder->getData()['default'] ?? [];
        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('input', \mb_strtolower($queries[0]['sql']));
        $this->assertStringNotContainsString('results', \mb_strtolower($queries[0]['sql']));
        $this->assertCount(0, $manager->getUnitOfWork()->getIdentityMap());
    }

    /** @test */
    public function identity_columns_are_nullable_and_indexed_without_requiring_unique_messages(): void
    {
        ProcessedMessageFactory::createOne();
        ProcessedMessageFactory::createOne(['messageId' => 'same', 'correlationId' => 'flow']);
        ProcessedMessageFactory::createOne(['messageId' => 'same', 'correlationId' => 'flow']);

        $container = self::getContainer();
        $manager = $container->get('doctrine')->getManager();
        $metadata = $manager->getClassMetadata(ProcessedMessage::class);

        foreach (['messageId', 'correlationId', 'causationId'] as $field) {
            $mapping = $metadata->getFieldMapping($field);
            $this->assertTrue(\is_array($mapping) ? $mapping['nullable'] : $mapping->nullable);
        }

        $columns = \array_column($metadata->table['indexes'], 'columns');
        $this->assertContains(['message_id'], $columns);
        $this->assertContains(['correlation_id'], $columns);

        $storage = $container->get(Storage::class);
        $this->assertCount(1, $storage->flow('flow'));
        $this->assertCount(0, $storage->flow('missing'));
    }
}
