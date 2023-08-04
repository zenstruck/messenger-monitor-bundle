<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Zenstruck\Messenger\Monitor\Command\PurgeCommand;
use Zenstruck\Messenger\Monitor\Command\SchedulePurgeCommand;
use Zenstruck\Messenger\Monitor\Command\SnapshotCommand;
use Zenstruck\Messenger\Monitor\History\HistoryListener;
use Zenstruck\Messenger\Monitor\History\ResultNormalizer;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\History\Storage\ORMStorage;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        ->set('zenstruck_messenger_monitor.history.orm_enabled', true)
    ;

    $container->services()
        ->set('zenstruck_messenger_monitor.history.storage', ORMStorage::class)
            ->args([
                service('doctrine'),
                abstract_arg('entity_class'),
            ])
            ->alias(Storage::class, 'zenstruck_messenger_monitor.history.storage')

        ->set('.zenstruck_messenger_monitor.history.result_normalizer', ResultNormalizer::class)

        ->set('.zenstruck_messenger_monitor.history.listener', HistoryListener::class)
            ->args([
                service('zenstruck_messenger_monitor.history.storage'),
                service('.zenstruck_messenger_monitor.history.result_normalizer'),
            ])
            ->tag('kernel.event_listener', ['method' => 'addMonitorStamp', 'event' => SendMessageToTransportsEvent::class])
            ->tag('kernel.event_listener', ['method' => 'receiveMessage', 'event' => WorkerMessageReceivedEvent::class])
            ->tag('kernel.event_listener', ['method' => 'handleSuccess', 'event' => WorkerMessageHandledEvent::class])
            ->tag('kernel.event_listener', ['method' => 'handleFailure', 'event' => WorkerMessageFailedEvent::class])

        ->set('.zenstruck_messenger_monitor.command.snapshot', SnapshotCommand::class)
            ->args([
                service('zenstruck_messenger_monitor.history.storage'),
                service('zenstruck_messenger_monitor.transport_monitor'),
            ])
            ->tag('console.command')

        ->set('.zenstruck_messenger_monitor.command.purge', PurgeCommand::class)
            ->args([
                service('zenstruck_messenger_monitor.history.storage'),
                service('zenstruck_messenger_monitor.transport_monitor'),
            ])
            ->tag('console.command')

        ->set('.zenstruck_messenger_monitor.command.schedule_purge', SchedulePurgeCommand::class)
            ->args([
                service('zenstruck_messenger_monitor.schedule_monitor'),
                service('zenstruck_messenger_monitor.history.storage'),
            ])
            ->tag('console.command')
    ;
};
