<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Zenstruck\Messenger\Monitor\ScheduleMonitor;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('zenstruck_messenger_monitor.schedule_monitor', ScheduleMonitor::class)
            ->args([
                tagged_locator('scheduler.schedule_provider', 'name'),
                service('zenstruck_messenger_monitor.transport_monitor'),
                service('zenstruck_messenger_monitor.history.storage')->nullOnInvalid(),
            ])
            ->alias(ScheduleMonitor::class, 'zenstruck_messenger_monitor.schedule_monitor')
    ;
};
