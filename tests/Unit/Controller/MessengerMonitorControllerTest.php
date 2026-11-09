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
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Zenstruck\Messenger\Monitor\Controller\MessengerMonitorController;
use Zenstruck\Messenger\Monitor\Schedules;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageA;
use Zenstruck\Messenger\Monitor\Transports;
use Zenstruck\Messenger\Monitor\Twig\ViewHelper;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Workers;

final class MessengerMonitorControllerTest extends TestCase
{
    /**
     * @test
     * @dataProvider unprotectedActions
     */
    public function mutation_requires_csrf_before_accessing_transports_or_schedules(string $action, ?string $token): void
    {
        $workers = new Workers(new WorkerCache(new NullAdapter()));
        $transports = new Transports(new ServiceLocator([
            'failed' => static fn() => throw new \LogicException('Transport accessed before CSRF validation.'),
        ]), $workers);
        $schedules = new Schedules(new ServiceLocator([
            'default' => static fn() => throw new \LogicException('Schedule accessed before CSRF validation.'),
        ]), $transports);
        $helper = new ViewHelper($transports, $workers, null, $schedules, null, null);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $controller = new class extends MessengerMonitorController {};
        $request = Request::create('/', 'POST', null === $token ? [] : ['_token' => $token]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CSRF protection is not enabled');

        match ($action) {
            'remove' => $controller->removeTransportMessage('failed', '1', $request, $helper),
            'retry' => $controller->retryFailedMessage('failed', '1', $request, $helper, $bus),
            'trigger' => $controller->triggerScheduleTask('default', 'task', 'async', $request, $schedules, $bus, $helper),
        };
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function unprotectedActions(): iterable
    {
        foreach (['remove', 'retry', 'trigger'] as $action) {
            yield $action.' without a token' => [$action, null];
            yield $action.' with a forged token' => [$action, 'forged'];
        }
    }

    /** @test */
    public function a_manually_retried_message_returns_to_its_original_transport_and_can_fail_again(): void
    {
        $failed = $this->failureTransport();
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
        $tokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $tokenManager->expects($this->once())->method('isTokenValid')->with(new CsrfToken('zenstruck_messenger_monitor_retry', 'valid-token'))->willReturn(true);
        $helper = new ViewHelper(new Transports($locator, $workers), $workers, null, null, null, $tokenManager);
        $controller = new class extends MessengerMonitorController {
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

        $controller->retryFailedMessage('failed', '1', new Request([], ['_token' => 'valid-token']), $helper, $bus);

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

    /**
     * @test
     * @dataProvider successfulActions
     */
    public function successful_actions_work_with_or_without_a_session(string $action, bool $withSession): void
    {
        $failed = $this->failureTransport();
        $failed->send(new Envelope(new MessageA(), [new SentToFailureTransportStamp('async')]));
        $workers = new Workers(new WorkerCache(new NullAdapter()));
        $transports = new Transports(new ServiceLocator(['failed' => static fn() => $failed]), $workers);
        $task = RecurringMessage::every('1 hour', new MessageA());
        $schedule = (new Schedule())->with($task);
        $schedules = new Schedules(new ServiceLocator(['default' => static fn() => $schedule]), $transports);
        $tokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $tokenManager->expects($this->once())->method('isTokenValid')->with(new CsrfToken('zenstruck_messenger_monitor_'.$action, 'valid-token'))->willReturn(true);
        $helper = new ViewHelper($transports, $workers, null, $schedules, null, $tokenManager);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly('remove' === $action ? 0 : 1))->method('dispatch')->willReturnCallback(static fn(object $message) => Envelope::wrap($message));
        $request = Request::create('/', 'POST', ['_token' => 'valid-token']);
        $session = new Session(new MockArraySessionStorage());

        if ($withSession) {
            $request->setSession($session);
        }

        $requests = new RequestStack();
        $requests->push($request);
        $container = new Container();
        $container->set('request_stack', $requests);
        $controller = new class extends MessengerMonitorController {
            /** @param array<array-key,mixed> $parameters */
            protected function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
            {
                return new RedirectResponse('/monitor');
            }
        };
        $controller->setContainer($container);

        $response = match ($action) {
            'remove' => $controller->removeTransportMessage('failed', '1', $request, $helper),
            'retry' => $controller->retryFailedMessage('failed', '1', $request, $helper, $bus),
            'trigger' => $controller->triggerScheduleTask('default', $task->getId(), 'async', $request, $schedules, $bus, $helper),
        };

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($withSession, $session->isStarted());
        $this->assertCount($withSession ? 1 : 0, $session->getFlashBag()->peek('success'));
        $this->assertCount('trigger' === $action ? 0 : 1, $failed->getRejected());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function successfulActions(): iterable
    {
        foreach (['remove', 'retry', 'trigger'] as $action) {
            yield $action.' with a session' => [$action, true];
            yield $action.' without a session' => [$action, false];
        }
    }

    private function failureTransport(): InMemoryTransport&ListableReceiverInterface
    {
        return new class extends InMemoryTransport implements ListableReceiverInterface {
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
    }
}
