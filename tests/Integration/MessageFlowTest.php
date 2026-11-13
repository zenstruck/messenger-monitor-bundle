<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\ChainMiddleware;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\FlowContextMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ChainStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Worker;
use Zenstruck\Messenger\Monitor\EventListener\AddMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\HandleMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\ReceiveMonitorStampListener;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\ResultNormalizer;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Entity\ProcessedMessage;

final class MessageFlowTest extends TestCase
{
    /** @test */
    public function records_native_chain_identities_and_preserves_causal_order(): void
    {
        if (!\class_exists(ChainStamp::class)) {
            $this->markTestSkipped('Requires Symfony 8.2 message flows.');
        }

        $transport = new InMemoryTransport();
        $dispatcher = new EventDispatcher();
        $senders = new SendersLocator(['*' => ['async']], new ServiceLocator(['async' => static fn() => $transport]));
        $proxy = $this->createMock(MessageBusInterface::class);
        $proxy->method('dispatch')->willReturnCallback(static function(object $message, array $stamps = []) use (&$bus): Envelope {
            return $bus->dispatch($message, $stamps);
        });
        $bus = new MessageBus([
            new FlowContextMiddleware(true),
            new DispatchAfterCurrentBusMiddleware(),
            new ChainMiddleware($proxy, $senders),
            new SendMessageMiddleware($senders, $dispatcher),
            new HandleMessageMiddleware(new HandlersLocator([\stdClass::class => [static fn() => 'handled']])),
        ]);
        $messages = [];
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->exactly(3))->method('save')->willReturnCallback(static function(Envelope $envelope, Results $results, ?\Throwable $error) use (&$messages): void {
            $messages[] = new ProcessedMessage($envelope, $results, $error);
        });
        $listener = new HandleMonitorStampListener($storage, new ResultNormalizer(__DIR__));
        $dispatcher->addListener(SendMessageToTransportsEvent::class, new AddMonitorStampListener());
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, new ReceiveMonitorStampListener([]));
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $listener->handleSuccess(...));
        $worker = new Worker(['async' => $transport], $bus, $dispatcher);
        $dispatcher->addListener(WorkerMessageHandledEvent::class, static function() use ($worker, &$messages): void {
            if (3 === \count($messages)) {
                $worker->stop();
            }
        });
        $bus->dispatch(ChainStamp::envelope(new \stdClass(), new \stdClass(), new \stdClass()));
        $worker->run(['sleep' => 0]);

        $ids = \array_map(static fn(ProcessedMessage $message) => $message->messageId(), $messages);
        $this->assertCount(3, \array_unique($ids));
        $this->assertNotNull($ids[0]);
        $this->assertSame([null, $ids[0], $ids[1]], \array_map(static fn(ProcessedMessage $message) => $message->causationId(), $messages));
        $this->assertSame([$ids[0], $ids[0], $ids[0]], \array_map(static fn(ProcessedMessage $message) => $message->correlationId(), $messages));
        $this->assertCount(3, \array_unique(\array_map(static fn(ProcessedMessage $message) => $message->runId(), $messages)));
    }
}
