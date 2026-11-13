<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Messenger\Event\HandlerFailureEvent;
use Symfony\Component\Messenger\Event\HandlerSuccessEvent;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Middleware\InboxMiddleware;
use Zenstruck\Messenger\Monitor\Command\PurgeCommand;
use Zenstruck\Messenger\Monitor\Command\SchedulePurgeCommand;
use Zenstruck\Messenger\Monitor\EventListener\AddMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\CaptureExecutionListener;
use Zenstruck\Messenger\Monitor\EventListener\HandleMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\ReceiveMonitorStampListener;
use Zenstruck\Messenger\Monitor\History\ResultNormalizer;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\History\Storage\ORMStorage;
use Zenstruck\Messenger\Monitor\Middleware\InboxMonitoringMiddleware;

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
            ->args([param('kernel.project_dir')])

        ->set('.zenstruck_messenger_monitor.listener.add_monitor_stamp', AddMonitorStampListener::class)
            ->tag('kernel.event_listener', ['method' => '__invoke', 'event' => SendMessageToTransportsEvent::class])

        ->set('.zenstruck_messenger_monitor.listener.receive_monitor_stamp', ReceiveMonitorStampListener::class)
            ->args([
                abstract_arg('exclude_classes'),
                service('serializer')->nullOnInvalid(),
                abstract_arg('store_input'),
            ])
            ->tag('kernel.event_listener', ['method' => '__invoke', 'event' => WorkerMessageReceivedEvent::class])

        ->set('.zenstruck_messenger_monitor.listener.handle_monitor_stamp', HandleMonitorStampListener::class)
            ->args([
                service('zenstruck_messenger_monitor.history.storage'),
                service('.zenstruck_messenger_monitor.history.result_normalizer'),
                service('logger')->nullOnInvalid(),
            ])
            ->tag('kernel.event_listener', ['method' => 'handleSuccess', 'event' => WorkerMessageHandledEvent::class, 'priority' => -100])
            ->tag('kernel.event_listener', ['method' => 'handleFailure', 'event' => WorkerMessageFailedEvent::class, 'priority' => -100])

        ->set('.zenstruck_messenger_monitor.command.purge', PurgeCommand::class)
            ->args([
                service('zenstruck_messenger_monitor.history.storage'),
                service('zenstruck_messenger_monitor.transports'),
            ])
            ->tag('console.command')

        ->set('.zenstruck_messenger_monitor.command.schedule_purge', SchedulePurgeCommand::class)
            ->args([
                service('zenstruck_messenger_monitor.schedules'),
                service('zenstruck_messenger_monitor.history.storage'),
            ])
            ->tag('console.command')
    ;

    if (\class_exists(HandlerSuccessEvent::class)) {
        $container->services()
            ->set('.zenstruck_messenger_monitor.listener.capture_execution', CaptureExecutionListener::class)
                ->tag('kernel.event_listener', ['event' => HandlerSuccessEvent::class])
                ->tag('kernel.event_listener', ['event' => HandlerFailureEvent::class])
        ;
    }

    if (\class_exists(InboxMiddleware::class)) {
        $container->services()
            ->set('.zenstruck_messenger_monitor.middleware.inbox', InboxMonitoringMiddleware::class)
                ->args([service('messenger.middleware.inbox')])
        ;
    }
};
