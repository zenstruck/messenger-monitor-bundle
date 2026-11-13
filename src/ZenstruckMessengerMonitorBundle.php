<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Zenstruck\Messenger\Monitor\DependencyInjection\Compiler\InboxMonitoringPass;
use Zenstruck\Messenger\Monitor\DependencyInjection\Compiler\SchedulerMonitoringPass;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class ZenstruckMessengerMonitorBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // MessengerPass builds the middleware lists at priority -16.
        $container->addCompilerPass(new InboxMonitoringPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -32);
        $container->addCompilerPass(new SchedulerMonitoringPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -32);

        if (\class_exists(DoctrineOrmMappingsPass::class)) {
            $reflection = new \ReflectionClass(DoctrineOrmMappingsPass::class);
            if ('aliasMap' === $reflection->getMethod('createXmlMappingDriver')->getParameters()[3]->getName()) {
                $container->addCompilerPass(DoctrineOrmMappingsPass::createXmlMappingDriver(
                    [__DIR__.'/../config/doctrine/mapping' => 'Zenstruck\Messenger\Monitor\History\Model'],
                    ['zenstruck_messenger_monitor.history.orm_manager'],
                    'zenstruck_messenger_monitor.history.orm_enabled',
                    [],
                    true,
                ));
            } else {
                $container->addCompilerPass(DoctrineOrmMappingsPass::createXmlMappingDriver(
                    [__DIR__.'/../config/doctrine/mapping' => 'Zenstruck\Messenger\Monitor\History\Model'],
                    ['zenstruck_messenger_monitor.history.orm_manager'],
                    'zenstruck_messenger_monitor.history.orm_enabled',
                    true, // @phpstan-ignore-line
                ));
            }
        }
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
