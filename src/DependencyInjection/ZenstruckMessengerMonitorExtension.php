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
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
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

        if ($entity = $mergedConfig['storage']['orm']['entity_class'] ?? null) {
            $loader->load('storage_orm.php');
            $container->getDefinition('zenstruck_messenger_monitor.history.storage')->setArgument(1, $entity);
        }
    }
}
