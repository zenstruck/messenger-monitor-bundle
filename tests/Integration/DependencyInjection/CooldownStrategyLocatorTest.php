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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Cooldown\CooldownStatus;
use Symfony\Component\Messenger\Cooldown\CooldownStrategyInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;
use Zenstruck\Messenger\Monitor\Worker\CooldownStrategyLocator;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;

final class CooldownStrategyLocatorTest extends TestCase
{
    /** @test */
    public function wraps_the_configured_locator_and_reports_custom_strategy_status(): void
    {
        if (!\interface_exists(CooldownStrategyInterface::class)) {
            $this->markTestSkipped('Requires Symfony 8.2 transport cooldowns.');
        }

        $container = $this->container();
        $container->getDefinition('.zenstruck_messenger_monitor.worker_listener')->setPublic(true);
        $container->register('custom_strategy', CooldownStrategyInterface::class)->setSynthetic(true)->setPublic(true);
        $container->register('messenger.cooldown_strategy_locator', ServiceLocator::class)
            ->setArguments([['async' => new ServiceClosureArgument(new Reference('custom_strategy'))]])
            ->addTag('container.service_locator')
            ->setPublic(true);
        $container->compile();
        $strategy = $this->createMock(CooldownStrategyInterface::class);
        $strategy->expects($this->once())->method('checkStatus')->with('async')->willReturn(CooldownStatus::indefinite());
        $container->set('custom_strategy', $strategy);
        $listener = $container->get('.zenstruck_messenger_monitor.worker_listener');
        $listener->onStart(new WorkerStartedEvent(new Worker([], new MessageBus())));
        $locator = $container->get('messenger.cooldown_strategy_locator');

        $this->assertInstanceOf(CooldownStrategyLocator::class, $locator);
        $this->assertFalse($locator->has('missing'));
        $locator->get('async')->checkStatus('async');
        $cache = new WorkerCache($container->get('cache.app'));
        $workers = \iterator_to_array($cache);
        $this->assertNotNull($workers[0]->cooldownFor('async'));
    }

    /** @test */
    public function compiles_without_messenger_cooldown_services(): void
    {
        $container = $this->container();
        $container->compile();

        $this->assertFalse($container->has('messenger.cooldown_strategy_locator'));
        $this->assertFalse($container->has('.zenstruck_messenger_monitor.cooldown_strategy_locator'));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('cache.app', ArrayAdapter::class)->setPublic(true);
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../../config'));
        $loader->load('services.php');

        return $container;
    }
}
