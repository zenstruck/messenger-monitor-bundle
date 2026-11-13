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
use Symfony\Component\Messenger\Inbox\InboxInterface;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\InboxMiddleware;
use Symfony\Component\Messenger\Stamp\MessageIdStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Zenstruck\Messenger\Monitor\DependencyInjection\Compiler\InboxMonitoringPass;
use Zenstruck\Messenger\Monitor\Middleware\InboxMonitoringMiddleware;
use Zenstruck\Messenger\Monitor\Stamp\InboxDeliveryStamp;
use Zenstruck\Messenger\Monitor\ZenstruckMessengerMonitorBundle;

final class InboxMonitoringPassTest extends TestCase
{
    /** @test */
    public function wraps_the_compiled_bus_without_replacing_symfonys_native_inbox(): void
    {
        if (!\class_exists(InboxMiddleware::class)) {
            $this->markTestSkipped('Requires Symfony 8.2 inbox support.');
        }

        $inbox = $this->createMock(InboxInterface::class);
        $inbox->expects($this->once())->method('record')->with('async', 'duplicate')->willReturn(false);
        $container = new ContainerBuilder();
        $container->register('inboxes', ServiceLocator::class)->setSynthetic(true)->setPublic(true);
        $container->register('messenger.middleware.inbox', InboxMiddleware::class)
            ->setArguments([new Reference('inboxes'), []])
            ->setPublic(true);
        $container->register('messenger.middleware.handle_message', HandleMessageMiddleware::class)
            ->setArguments([new HandlersLocator([]), true]);
        $container->register('.zenstruck_messenger_monitor.middleware.inbox', InboxMonitoringMiddleware::class)
            ->setArguments([new Reference('messenger.middleware.inbox')]);
        $container->register('deferred', DispatchAfterCurrentBusMiddleware::class)
            ->setArguments([null, new Reference('messenger.middleware.inbox')])
            ->setPublic(true);
        $container->register('bus', MessageBus::class)->setArguments([[]])->addTag('messenger.bus')->setPublic(true);
        $container->setParameter('bus.middleware', [['id' => 'inbox'], ['id' => 'handle_message']]);
        $container->addCompilerPass(new MessengerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -16);
        $bundle = new ZenstruckMessengerMonitorBundle();
        $bundle->build($container);
        $container->compile();
        $container->set('inboxes', new ServiceLocator(['async' => static fn() => $inbox]));

        $this->assertInstanceOf(InboxMiddleware::class, $container->get('messenger.middleware.inbox'));
        $this->assertInstanceOf(DispatchAfterCurrentBusMiddleware::class, $container->get('deferred'));
        $bus = $container->get('bus');
        $envelope = $bus->dispatch(new Envelope(new \stdClass(), [
            new ReceivedStamp('async'),
            new MessageIdStamp('duplicate'),
            new InboxDeliveryStamp(),
        ]));

        $this->assertTrue($envelope->last(InboxDeliveryStamp::class)->isSkipped());
    }

    /** @test */
    public function removes_the_unused_wrapper_when_the_native_inbox_is_unavailable(): void
    {
        $container = new ContainerBuilder();
        $container->register('.zenstruck_messenger_monitor.middleware.inbox', InboxMonitoringMiddleware::class)
            ->setArguments([new Reference('messenger.middleware.inbox')]);
        $container->addCompilerPass(new InboxMonitoringPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -32);
        $container->compile();

        $this->assertFalse($container->hasDefinition('.zenstruck_messenger_monitor.middleware.inbox'));
    }
}
