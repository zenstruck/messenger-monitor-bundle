<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Zenstruck\Messenger\Monitor\Schedules;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('zenstruck_messenger_monitor.schedules', Schedules::class)
            ->args([
                tagged_locator('scheduler.schedule_provider', 'name'),
                service('zenstruck_messenger_monitor.transport_monitor'),
                service('zenstruck_messenger_monitor.history.storage')->nullOnInvalid(),
            ])
            ->alias(Schedules::class, 'zenstruck_messenger_monitor.schedules')
    ;
};
