<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/** @internal */
final class SchedulerMonitoringPass implements CompilerPassInterface
{
    private const MIDDLEWARE = '.zenstruck_messenger_monitor.middleware.scheduler';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::MIDDLEWARE)) {
            return;
        }

        foreach ($container->findTaggedServiceIds('messenger.bus') as $bus => $tags) {
            $definition = $container->getDefinition($bus);
            $this->prependMiddleware($definition);
        }
    }

    private function prependMiddleware(Definition $bus): void
    {
        $middleware = $bus->getArguments()[0] ?? null;

        if (!$middleware instanceof IteratorArgument) {
            return;
        }

        $references = $middleware->getValues();
        $middleware->setValues([new Reference(self::MIDDLEWARE), ...$references]);
    }
}
