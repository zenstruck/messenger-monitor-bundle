<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Integration\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\DependencyInjection\MessengerPass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Handler\RedispatchMessageHandler;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;
use Zenstruck\Messenger\Monitor\Middleware\SchedulerMonitoringMiddleware;
use Zenstruck\Messenger\Monitor\Stamp\SchedulerDeliveryStamp;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageA;
use Zenstruck\Messenger\Monitor\ZenstruckMessengerMonitorBundle;

final class SchedulerMonitoringPassTest extends TestCase
{
    /**
     * @test
     * @dataProvider configurations
     */
    public function tracks_forwarding_between_compiled_buses_only_when_storage_is_configured(bool $storageEnabled): void
    {
        $container = new ContainerBuilder();
        $container->register('senders', SendersLocator::class)->setSynthetic(true)->setPublic(true);
        $container->register('messenger.middleware.send_message', SendMessageMiddleware::class)
            ->setArguments([new Reference('senders')]);
        $handler = static function(RedispatchMessage $message) use (&$forwardBus): mixed {
            return (new RedispatchMessageHandler($forwardBus))($message);
        };
        $container->register('messenger.middleware.handle_message', HandleMessageMiddleware::class)
            ->setArguments([new HandlersLocator([RedispatchMessage::class => [$handler]]), true]);

        if ($storageEnabled) {
            $container->register('.zenstruck_messenger_monitor.middleware.scheduler', SchedulerMonitoringMiddleware::class);
        }

        foreach (['scheduler_bus', 'forward_bus'] as $name) {
            $container->register($name, MessageBus::class)->setArguments([[]])->addTag('messenger.bus')->setPublic(true);
            $container->setParameter($name.'.middleware', [['id' => 'send_message'], ['id' => 'handle_message']]);
        }

        $container->addCompilerPass(new MessengerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -16);
        $bundle = new ZenstruckMessengerMonitorBundle();
        $bundle->build($container);
        $container->compile();
        $target = new InMemoryTransport();
        $container->set('senders', new SendersLocator([MessageA::class => ['async']], new ServiceLocator(['async' => static fn() => $target])));
        $forwardBus = $container->get('forward_bus');
        $bus = $container->get('scheduler_bus');
        $delivery = new SchedulerDeliveryStamp();
        $scheduled = new ScheduledStamp(new MessageContext('default', 'task', new PeriodicalTrigger('1 hour'), new \DateTimeImmutable()));
        $message = new RedispatchMessage(new Envelope(new MessageA(), [$scheduled]), 'async');
        $bus->dispatch(new Envelope($message, [new ReceivedStamp('scheduler_default'), $scheduled, $delivery]));

        $this->assertCount(1, $target->getSent());
        $this->assertSame($storageEnabled, $delivery->isForwarded());
    }

    /** @return iterable<string, array{bool}> */
    public static function configurations(): iterable
    {
        yield 'storage enabled' => [true];
        yield 'storage disabled' => [false];
    }
}
