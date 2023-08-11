<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Mailer\Event\MessageEvent;
use Zenstruck\Messenger\Monitor\EventListener\AutoStampEmailListener;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('.zenstruck_messenger_monitor.event_listener.auto_stamp_emails', AutoStampEmailListener::class)
        ->tag('kernel.event_listener', ['method' => '__invoke', 'event' => MessageEvent::class])
    ;
};
