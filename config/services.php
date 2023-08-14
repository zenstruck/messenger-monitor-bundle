<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Zenstruck\Messenger\Monitor\Command\MonitorCommand;
use Zenstruck\Messenger\Monitor\Transports;
use Zenstruck\Messenger\Monitor\Twig\ViewHelper;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Worker\WorkerListener;
use Zenstruck\Messenger\Monitor\Workers;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('zenstruck_messenger_monitor.transports', Transports::class)
            ->args([
                tagged_locator('messenger.receiver', 'alias'),
                service('zenstruck_messenger_monitor.workers'),
            ])
            ->alias(Transports::class, 'zenstruck_messenger_monitor.transports')

        ->set('.zenstruck_messenger_monitor.worker_cache', WorkerCache::class)
            ->args([
                service('cache.app'),
            ])

        ->set('zenstruck_messenger_monitor.workers', Workers::class)
            ->args([
                service('.zenstruck_messenger_monitor.worker_cache'),
            ])
            ->alias(Workers::class, 'zenstruck_messenger_monitor.workers')

        ->set('.zenstruck_messenger_monitor.worker_listener', WorkerListener::class)
            ->args([
                service('.zenstruck_messenger_monitor.worker_cache'),
            ])
            ->tag('kernel.event_listener', ['method' => 'onStart', 'event' => WorkerStartedEvent::class])
            ->tag('kernel.event_listener', ['method' => 'onStop', 'event' => WorkerStoppedEvent::class])
            ->tag('kernel.event_listener', ['method' => 'onRunning', 'event' => WorkerRunningEvent::class])

        ->set('.zenstruck_messenger_monitor.command.monitor', MonitorCommand::class)
            ->args([
                service('zenstruck_messenger_monitor.workers'),
                service('zenstruck_messenger_monitor.transports'),
                service('zenstruck_messenger_monitor.history.storage')->nullOnInvalid(),
            ])
            ->tag('console.command')

        ->set('zenstruck_messenger_monitor.view_helper', ViewHelper::class)
            ->args([
                service('zenstruck_messenger_monitor.transports'),
                service('zenstruck_messenger_monitor.workers'),
                service('zenstruck_messenger_monitor.history.storage')->nullOnInvalid(),
                service('zenstruck_messenger_monitor.schedules')->nullOnInvalid(),
                service('time.datetime_formatter')->nullOnInvalid(),
            ])
            ->alias(ViewHelper::class, 'zenstruck_messenger_monitor.view_helper')
    ;
};
