<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Zenstruck\Messenger\Monitor\TransportMonitor;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Worker\WorkerListener;
use Zenstruck\Messenger\Monitor\WorkerMonitor;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('zenstruck_messenger_monitor.transport_monitor', TransportMonitor::class)
            ->args([
                tagged_locator('messenger.receiver', 'alias'),
            ])
            ->alias(TransportMonitor::class, 'zenstruck_messenger_monitor.transport_monitor')

        ->set('.zenstruck_messenger_monitor.worker_cache', WorkerCache::class)
            ->args([
                service('cache.app'),
            ])

        ->set('zenstruck_messenger_monitor.worker_monitor', WorkerMonitor::class)
            ->args([
                service('.zenstruck_messenger_monitor.worker_cache'),
            ])
            ->alias(WorkerMonitor::class, 'zenstruck_messenger_monitor.worker_monitor')

        ->set('.zenstruck_messenger_monitor.worker_listener', WorkerListener::class)
            ->args([
                service('.zenstruck_messenger_monitor.worker_cache'),
            ])
            ->tag('kernel.event_listener', ['method' => 'onStart'])
            ->tag('kernel.event_listener', ['method' => 'onStop'])
            ->tag('kernel.event_listener', ['method' => 'onRunning'])
    ;
};
