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

/**
 * Keep the native inbox service for Symfony's typed deferred-dispatch dependency.
 *
 * @internal
 */
final class InboxMonitoringPass implements CompilerPassInterface
{
    private const MIDDLEWARE = '.zenstruck_messenger_monitor.middleware.inbox';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::MIDDLEWARE)) {
            return;
        }

        if (!$container->has('messenger.middleware.inbox')) {
            $container->removeDefinition(self::MIDDLEWARE);

            return;
        }

        foreach ($container->findTaggedServiceIds('messenger.bus') as $bus => $tags) {
            $this->wrapInbox($container->getDefinition($bus));
        }
    }

    private function wrapInbox(Definition $bus): void
    {
        $middleware = $bus->getArguments()[0] ?? null;

        if (!$middleware instanceof IteratorArgument) {
            return;
        }

        $references = $middleware->getValues();
        $middleware->setValues(\array_map($this->wrapReference(...), $references));
    }

    private function wrapReference(mixed $middleware): mixed
    {
        if ($middleware instanceof Reference && 'messenger.middleware.inbox' === (string) $middleware) {
            return new Reference(self::MIDDLEWARE);
        }

        return $middleware;
    }
}
