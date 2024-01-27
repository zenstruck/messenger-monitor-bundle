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

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Scheduler\Schedule;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;
use Zenstruck\Messenger\Monitor\Twig\ViewHelper;

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
                ->arrayNode('live_components')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('role')
                            ->info('Role required to view live components.')
                            ->defaultValue('ROLE_MESSENGER_MONITOR')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('storage')
                    ->children()
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

        $cache = new Reference($mergedConfig['cache']['pool'], ContainerBuilder::NULL_ON_INVALID_REFERENCE);
        if($cache === null) {
            throw new LogicException(\sprintf('Cache pool "%s" not found.', $mergedConfig['cache']['pool']));
        }

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
            $container->getDefinition('zenstruck_messenger_monitor.history.storage')->setArgument(1, $entity);

            if (!\class_exists(Schedule::class)) {
                $container->removeDefinition('.zenstruck_messenger_monitor.command.schedule_purge');
            }
        }

        if ($mergedConfig['live_components']['enabled']) {
            $loader->load('live_components.php');

            self::loadLiveComponents($container, $mergedConfig['live_components']);
        }
    }

    /**
     * @param mixed[] $config
     */
    private static function loadLiveComponents(ContainerBuilder $container, array $config): void
    {
        if (!isset($container->getParameter('kernel.bundles')['LiveComponentBundle'])) {
            throw new LogicException('"LiveComponentBundle" (symfony/ux-live-component) must be installed to use live components.');
        }

        if (!isset($container->getParameter('kernel.bundles')['StimulusBundle'])) {
            throw new LogicException('The "StimulusBundle" (symfony/stimulus-bundle) must be installed to use live components.');
        }

        if (!\interface_exists(AssetMapperInterface::class) && !isset($container->getParameter('kernel.bundles')['WebpackEncoreBundle'])) {
            throw new \LogicException('symfony/asset-mapper or encore must be available to use live components.');
        }

        $container->setParameter('zenstruck_messenger_monitor.security_role', $config['role']);
        $container->getDefinition('zenstruck_messenger_monitor.view_helper')
            ->setArgument(5, \interface_exists(AssetMapperInterface::class) ? ViewHelper::ASSET_MAPPER : ViewHelper::ENCORE)
        ;
    }
}
