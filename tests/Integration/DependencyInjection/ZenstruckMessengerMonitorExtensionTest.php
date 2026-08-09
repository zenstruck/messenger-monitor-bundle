<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Integration\DependencyInjection;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\ContainerBuilderHasAliasConstraint;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\ContainerBuilderHasServiceDefinitionConstraint;
use PHPUnit\Framework\Constraint\LogicalNot;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Zenstruck\Messenger\Monitor\DependencyInjection\ZenstruckMessengerMonitorExtension;
use Zenstruck\Messenger\Monitor\EventListener\AddMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\HandleMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\ReceiveMonitorStampListener;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\History\Storage\ORMStorage;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Entity\ProcessedMessage as ProcessedMessageImpl;
use Zenstruck\Messenger\Monitor\Transports;
use Zenstruck\Messenger\Monitor\Workers;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class ZenstruckMessengerMonitorExtensionTest extends AbstractExtensionTestCase
{
    /**
     * @test
     */
    public function no_config(): void
    {
        $this->load();

        $this->assertContainerBuilderHasAlias(Transports::class, 'zenstruck_messenger_monitor.transports');
        $this->assertContainerBuilderHasAlias(Workers::class, 'zenstruck_messenger_monitor.workers');
        $this->assertThat($this->container, new LogicalNot(new ContainerBuilderHasAliasConstraint(Storage::class)));
        $this->assertThat($this->container, new LogicalNot(new ContainerBuilderHasServiceDefinitionConstraint('.zenstruck_messenger_monitor.listener.add_monitor_stamp')));
        $this->assertThat($this->container, new LogicalNot(new ContainerBuilderHasServiceDefinitionConstraint('.zenstruck_messenger_monitor.listener.receive_monitor_stamp')));
        $this->assertThat($this->container, new LogicalNot(new ContainerBuilderHasServiceDefinitionConstraint('.zenstruck_messenger_monitor.listener.handle_monitor_stamp')));
    }

    /**
     * @test
     */
    public function orm_config(): void
    {
        $this->load(['storage' => ['orm' => ['entity_class' => ProcessedMessageImpl::class]]]);

        $this->assertContainerBuilderHasAlias(Transports::class, 'zenstruck_messenger_monitor.transports');
        $this->assertContainerBuilderHasAlias(Workers::class, 'zenstruck_messenger_monitor.workers');
        $this->assertContainerBuilderHasService('zenstruck_messenger_monitor.history.storage', ORMStorage::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('zenstruck_messenger_monitor.history.storage', 1, ProcessedMessageImpl::class);
        $this->assertContainerBuilderHasAlias(Storage::class, 'zenstruck_messenger_monitor.history.storage');
        $this->assertContainerBuilderHasService('.zenstruck_messenger_monitor.listener.add_monitor_stamp', AddMonitorStampListener::class);
        $this->assertContainerBuilderHasService('.zenstruck_messenger_monitor.listener.receive_monitor_stamp', ReceiveMonitorStampListener::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('.zenstruck_messenger_monitor.listener.receive_monitor_stamp', 0, []);
        $this->assertContainerBuilderHasService('.zenstruck_messenger_monitor.listener.handle_monitor_stamp', HandleMonitorStampListener::class);
    }

    /**
     * @test
     */
    public function orm_config_uses_default_entity_manager(): void
    {
        $this->load(['storage' => ['orm' => ['entity_class' => ProcessedMessageImpl::class]]]);

        $this->assertContainerBuilderHasParameter('zenstruck_messenger_monitor.history.orm_manager', 'default');
    }

    /**
     * @test
     */
    public function orm_config_with_custom_entity_manager(): void
    {
        $this->load(['storage' => ['orm' => ['entity_class' => ProcessedMessageImpl::class, 'entity_manager' => 'custom']]]);

        $this->assertContainerBuilderHasParameter('zenstruck_messenger_monitor.history.orm_manager', 'custom');
    }

    /**
     * @test
     */
    public function storage_with_excluded_classes(): void
    {
        $this->load(['storage' => [
            'orm' => ['entity_class' => ProcessedMessageImpl::class],
            'exclude' => ['stdClass'],
        ]]);

        $this->assertContainerBuilderHasServiceDefinitionWithArgument('.zenstruck_messenger_monitor.listener.receive_monitor_stamp', 0, [\stdClass::class]);
    }

    /**
     * @test
     */
    public function invalid_exclude_class(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['storage' => [
            'exclude' => ['invalid'],
        ]]);
    }

    /**
     * @test
     */
    public function non_orm_entity(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['storage' => ['orm' => ['entity_class' => 'invalid']]]);
    }

    /**
     * @test
     */
    public function non_extended_orm_entity(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['storage' => ['orm' => ['entity_class' => ProcessedMessage::class]]]);
    }

    /**
     * @test
     */
    public function cache_config(): void
    {
        $this->load(['cache' => ['pool' => 'cache.app', 'expired_worker_ttl' => 7200]]);

        $workerCacheDefinition = $this->container->getDefinition('.zenstruck_messenger_monitor.worker_cache');

        $this->assertEquals('cache.app', (string) $workerCacheDefinition->getArgument(0));
        $this->assertEquals(7200, $workerCacheDefinition->getArgument(1));
    }

    protected function getContainerExtensions(): array
    {
        return [new ZenstruckMessengerMonitorExtension()];
    }
}
