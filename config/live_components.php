<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Zenstruck\Messenger\Monitor\Twig\Component\SnapshotComponent;
use Zenstruck\Messenger\Monitor\Twig\Component\TransportsComponent;
use Zenstruck\Messenger\Monitor\Twig\Component\WorkersComponent;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->set(WorkersComponent::class)
        ->set(TransportsComponent::class)
        ->set(SnapshotComponent::class)
    ;
};
