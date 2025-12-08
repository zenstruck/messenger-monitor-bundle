<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Scheduler\Schedule;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class ZenstruckMessengerMonitorExtension extends ConfigurableExtension implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $builder = new TreeBuilder('zenstruck_messenger_monitor');

        $builder->getRootNode() // @phpstan-ignore-line
            ->children()
                ->arrayNode('storage')
                    ->children()
                        ->arrayNode('exclude')
                            ->info('Message classes to disable monitoring for (can be abstract/interface)')
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(fn($v) => !\class_exists($v) && !\interface_exists($v))
                                    ->thenInvalid('Class/interface does not exist.')
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('orm')
                            ->children()
                                ->scalarNode('entity_class')
                                    ->info(\sprintf('Your Doctrine entity class that extends "%s"', ProcessedMessage::class))
                                    ->validate()
                                        ->ifTrue(fn($v) => ProcessedMessage::class === $v || !\is_a($v, ProcessedMessage::class, true))
                                        ->thenInvalid(\sprintf('Your Doctrine entity class must extend "%s"', ProcessedMessage::class))
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('pool')
                            ->info('Cache pool to use for worker cache.')
                            ->defaultValue('cache.app')
                        ->end()
                        ->integerNode('expired_worker_ttl')
                            ->info('How long to keep expired workers in cache (in seconds).')
                            ->defaultValue(3600)
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $builder;
    }

    public function getConfiguration(array $config, ContainerBuilder $container): ConfigurationInterface // @phpstan-ignore-line
    {
        return $this;
    }

    protected function loadInternal(array $mergedConfig, ContainerBuilder $container): void // @phpstan-ignore-line
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');

        $container->getDefinition('.zenstruck_messenger_monitor.worker_cache')
            ->setArgument(0, new Reference($mergedConfig['cache']['pool']))
            ->setArgument(1, $mergedConfig['cache']['expired_worker_ttl']);

        if (\class_exists(Schedule::class)) {
            $loader->load('schedule.php');
        }

        if (\class_exists(MessageEvent::class)) {
            $loader->load('mailer.php');
        }

        if ($entity = $mergedConfig['storage']['orm']['entity_class'] ?? null) {
            $loader->load('storage_orm.php');
            $container->getDefinition('zenstruck_messenger_monitor.history.storage')
                ->setArgument(1, $entity)
            ;
            $container->getDefinition('.zenstruck_messenger_monitor.listener.receive_monitor_stamp')
                ->setArgument(0, $mergedConfig['storage']['exclude'])
            ;

            if (!\class_exists(Schedule::class)) {
                $container->removeDefinition('.zenstruck_messenger_monitor.command.schedule_purge');
            }
        }
    }
}
