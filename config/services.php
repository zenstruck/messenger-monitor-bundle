<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Zenstruck\Messenger\Monitor\Command\MonitorCommand;
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
            ->tag('kernel.event_listener', ['method' => 'onStart', 'event' => WorkerStartedEvent::class])
            ->tag('kernel.event_listener', ['method' => 'onStop', 'event' => WorkerStoppedEvent::class])
            ->tag('kernel.event_listener', ['method' => 'onRunning', 'event' => WorkerRunningEvent::class])

        ->set('.zenstruck_messenger_monitor.command.monitor', MonitorCommand::class)
            ->args([
                service('zenstruck_messenger_monitor.worker_monitor'),
                service('zenstruck_messenger_monitor.transport_monitor'),
                service('zenstruck_messenger_monitor.history.storage')->nullOnInvalid(),
            ])
            ->tag('console.command')
    ;
};
