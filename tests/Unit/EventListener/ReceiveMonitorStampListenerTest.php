<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;
use Zenstruck\Messenger\Monitor\EventListener\ReceiveMonitorStampListener;
use Zenstruck\Messenger\Monitor\Stamp\DisableMonitoringStamp;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class ReceiveMonitorStampListenerTest extends TestCase
{
    /**
     * @test
     */
    public function skips_standard_messages_without_monitor_stamp(): void
    {
        $listener = new ReceiveMonitorStampListener([]);
        $envelope = new Envelope(new \stdClass());
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $this->assertEmpty($event->getEnvelope()->all(MonitorStamp::class));

        $listener->__invoke($event);

        $this->assertEmpty($event->getEnvelope()->all(MonitorStamp::class));
    }

    /**
     * @test
     */
    public function marks_standard_message_as_received(): void
    {
        $listener = new ReceiveMonitorStampListener([]);
        $envelope = new Envelope(new \stdClass(), [new MonitorStamp()]);
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $this->assertFalse($event->getEnvelope()->last(MonitorStamp::class)->isReceived());

        $listener->__invoke($event);

        $this->assertTrue($event->getEnvelope()->last(MonitorStamp::class)->isReceived());
        $this->assertSame('foo', $event->getEnvelope()->last(MonitorStamp::class)->transport());
        $this->assertEmpty($event->getEnvelope()->all(TagStamp::class));
    }

    /**
     * @test
     */
    public function marks_scheduled_message_as_received(): void
    {
        $listener = new ReceiveMonitorStampListener([]);
        $envelope = new Envelope(new \stdClass(), [new ScheduledStamp(new MessageContext(
            'default',
            'id',
            $this->createMock(TriggerInterface::class),
            new \DateTimeImmutable(),
        ))]);
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $this->assertNull($event->getEnvelope()->last(MonitorStamp::class));

        $listener->__invoke($event);

        $this->assertTrue($event->getEnvelope()->last(MonitorStamp::class)->isReceived());
        $this->assertSame('foo', $event->getEnvelope()->last(MonitorStamp::class)->transport());
        $this->assertCount(1, $event->getEnvelope()->all(TagStamp::class));
        $this->assertSame('schedule:default:id', $event->getEnvelope()->last(TagStamp::class)->value);
    }

    /**
     * @test
     */
    public function can_disable_monitoring_with_envelope_stamp(): void
    {
        $listener = new ReceiveMonitorStampListener([]);
        $envelope = new Envelope(new \stdClass(), [new MonitorStamp(), new DisableMonitoringStamp()]);
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $listener->__invoke($event);

        $this->assertFalse($event->getEnvelope()->last(MonitorStamp::class)->isReceived());
    }

    /**
     * @test
     */
    public function can_disable_monitoring_message_attribute(): void
    {
        $listener = new ReceiveMonitorStampListener([]);
        $envelope = new Envelope(new DisabledMonitoringMessage(), [new MonitorStamp()]);
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $listener->__invoke($event);

        $this->assertFalse($event->getEnvelope()->last(MonitorStamp::class)->isReceived());
    }

    /**
     * @test
     */
    public function can_disable_monitoring_message_interface_attribute(): void
    {
        $listener = new ReceiveMonitorStampListener([]);
        $envelope = new Envelope(new DisableMonitoringViaInterface(), [new MonitorStamp()]);
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $listener->__invoke($event);

        $this->assertFalse($event->getEnvelope()->last(MonitorStamp::class)->isReceived());
    }

    /**
     * @test
     */
    public function can_disable_monitoring_message_attribute_without_handler(): void
    {
        $listener = new ReceiveMonitorStampListener([]);
        $envelope = new Envelope(new DisabledMonitoringWithoutHandlerMessage(), [new MonitorStamp()]);
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $listener->__invoke($event);

        $this->assertFalse($event->getEnvelope()->last(MonitorStamp::class)->isReceived());
    }

    /**
     * @test
     */
    public function can_disable_monitoring_message_without_handler(): void
    {
        $listener = new ReceiveMonitorStampListener([]);
        $envelope = new Envelope(new \stdClass(), [
            new MonitorStamp(),
            new DisableMonitoringStamp(true),
        ]);
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $listener->__invoke($event);

        $this->assertFalse($event->getEnvelope()->last(MonitorStamp::class)->isReceived());
    }

    /**
     * @test
     */
    public function handle_disable_monitoring_message_attribute_with_handler(): void
    {
        $listener = new ReceiveMonitorStampListener([]);
        $envelope = new Envelope(
            new EnabledMonitoringWithHandlerMessage(),
            [
                new MonitorStamp(),
                new HandledStamp(EnabledMonitoringWithHandlerMessageHandler::class, 'result'),
            ],
        );
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $listener->__invoke($event);

        $this->assertTrue($event->getEnvelope()->last(MonitorStamp::class)->isReceived());
    }

    /**
     * @test
     */
    public function handle_disable_monitoring_message_with_handler(): void
    {
        $listener = new ReceiveMonitorStampListener([]);
        $envelope = new Envelope(
            new \stdClass(),
            [
                new MonitorStamp(),
                new DisableMonitoringStamp(true),
                new HandledStamp(EnabledMonitoringWithHandlerMessageHandler::class, 'result'),
            ],
        );
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $listener->__invoke($event);

        $this->assertTrue($event->getEnvelope()->last(MonitorStamp::class)->isReceived());
    }

    /**
     * @test
     */
    public function can_disable_monitoring_message_via_config(): void
    {
        $listener = new ReceiveMonitorStampListener(
            [
                MessageToDisableViaConfig::class,
            ]
        );
        $envelope = new Envelope(new MessageToDisableViaConfig(), [new MonitorStamp()]);
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $listener->__invoke($event);

        $this->assertFalse($event->getEnvelope()->last(MonitorStamp::class)->isReceived());
    }

    /**
     * @test
     */
    public function can_disable_extended_monitoring_message_via_config(): void
    {
        $listener = new ReceiveMonitorStampListener(
            [
                MessageToDisableViaConfig::class,
            ]
        );
        $envelope = new Envelope(new ExtendedMessageToDisableViaConfig(), [new MonitorStamp()]);
        $event = new WorkerMessageReceivedEvent($envelope, 'foo');

        $listener->__invoke($event);

        $this->assertFalse($event->getEnvelope()->last(MonitorStamp::class)->isReceived());
    }
}

#[DisableMonitoringStamp]
class DisabledMonitoringMessage
{
}

#[DisableMonitoringStamp(true)]
class DisabledMonitoringWithoutHandlerMessage
{
}

#[DisableMonitoringStamp(true)]
class EnabledMonitoringWithHandlerMessage
{
}

#[DisableMonitoringStamp]
interface DisableInterface
{
}

abstract class ParentDisableMonitoringViaInterface implements DisableInterface
{
}

class DisableMonitoringViaInterface extends ParentDisableMonitoringViaInterface
{
}

class MessageToDisableViaConfig
{
}

class ExtendedMessageToDisableViaConfig extends MessageToDisableViaConfig
{
}

class EnabledMonitoringWithHandlerMessageHandler
{
    public function __invoke(EnabledMonitoringWithHandlerMessage $message): void
    {
    }
}
