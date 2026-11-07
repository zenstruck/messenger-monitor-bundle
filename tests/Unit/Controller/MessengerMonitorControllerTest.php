<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Zenstruck\Messenger\Monitor\Controller\MessengerMonitorController;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageA;
use Zenstruck\Messenger\Monitor\Transports;
use Zenstruck\Messenger\Monitor\Twig\ViewHelper;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Workers;

final class MessengerMonitorControllerTest extends TestCase
{
    /** @test */
    public function a_manually_retried_message_returns_to_its_original_transport_and_can_fail_again(): void
    {
        $failed = new class extends InMemoryTransport implements ListableReceiverInterface {
            /** @return list<Envelope> */
            public function all(?int $limit = null): array
            {
                return \array_slice([...$this->get()], 0, $limit);
            }

            public function find(mixed $id): ?Envelope
            {
                return $this->all()[(int) $id - 1] ?? null;
            }
        };

        $original = new InMemoryTransport();
        $other = new InMemoryTransport();
        $locator = new ServiceLocator([
            'failed' => static fn() => $failed,
            'original' => static fn() => $original,
            'other' => static fn() => $other,
        ]);
        $senders = new SendersLocator([MessageA::class => ['other']], $locator);
        $bus = new MessageBus([new SendMessageMiddleware($senders)]);
        $workers = new Workers(new WorkerCache(new NullAdapter()));
        $helper = new ViewHelper(new Transports($locator, $workers), $workers, null, null, null, null);
        $controller = new class extends MessengerMonitorController {
            protected function addFlash(string $type, mixed $message): void
            {
            }

            /** @param array<array-key,mixed> $parameters */
            protected function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
            {
                return new RedirectResponse('/transport/failed');
            }
        };

        $error = new \RuntimeException('Handler failed');
        $failedEnvelope = $failed->send(new Envelope(new MessageA(), [
            new SentToFailureTransportStamp('original'),
            new RedeliveryStamp(2),
            new ErrorDetailsStamp($error::class, $error->getCode(), $error->getMessage()),
            new ReceivedStamp('original'),
            new TransportNamesStamp('other'),
        ]));

        $controller->retryFailedMessage('failed', '1', new Request(), $helper, $bus);

        $this->assertSame([], $other->getSent());
        $retriedMessages = $original->getSent();
        $this->assertCount(1, $retriedMessages);
        $this->assertSame([$failedEnvelope], $failed->getRejected());
        $retriedEnvelope = $retriedMessages[0];
        $this->assertNull($retriedEnvelope->last(RedeliveryStamp::class));
        $this->assertNull($retriedEnvelope->last(ErrorDetailsStamp::class));

        $listener = new SendFailedMessageToFailureTransportListener(new ServiceLocator(['original' => static fn() => $failed]));
        $listener->onMessageFailed(new WorkerMessageFailedEvent($retriedEnvelope, 'original', $error));

        $this->assertCount(2, $failed->getSent());
    }
}
