<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\HandlerFailureEvent;
use Symfony\Component\Messenger\Event\HandlerSuccessEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Contracts\Service\ContainerProviderInterface;
use Zenstruck\Messenger\Monitor\EventListener\CaptureExecutionListener;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageA;
use Zenstruck\Messenger\Monitor\Tests\Fixture\ParallelWorkerHandler;

$dispatcher = new EventDispatcher();

if (\class_exists(CaptureExecutionListener::class)) {
    $listener = new CaptureExecutionListener();
    $dispatcher->addListener(HandlerSuccessEvent::class, $listener);
    $dispatcher->addListener(HandlerFailureEvent::class, $listener);
}

$bus = new MessageBus([
    new HandleMessageMiddleware(new HandlersLocator([MessageA::class => [new ParallelWorkerHandler()]]), dispatcher: $dispatcher),
]);

return new class($bus) implements ContainerProviderInterface {
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function getContainer(): ContainerInterface
    {
        return new ServiceLocator(['messenger.routable_message_bus' => fn() => $this->bus]);
    }
};
