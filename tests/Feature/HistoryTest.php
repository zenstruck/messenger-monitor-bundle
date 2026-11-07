<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Feature;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;
use Zenstruck\Messenger\Monitor\Stamp\DescriptionStamp;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Factory\ProcessedMessageFactory;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageA;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageAHandler;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageB;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageC;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageCHandler1;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageCHandler2;
use Zenstruck\Messenger\Test\InteractsWithMessenger;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class HistoryTest extends KernelTestCase
{
    use Factories, InteractsWithMessenger, ResetDatabase;

    /**
     * @test
     */
    public function flow_for_single_handler(): void
    {
        ProcessedMessageFactory::assert()->empty();

        $this->bus()->dispatch(new MessageA(return: 'foo'));

        $this->transport()->processOrFail(1);

        ProcessedMessageFactory::assert()->count(1);

        $message = ProcessedMessageFactory::first();
        $this->assertSame(MessageA::class, $message->type()->class());
        $this->assertSame('async', $message->transport());
        $this->assertFalse($message->isFailure());
        $this->assertCount(1, $message->results());
        $this->assertSame(['data' => 'foo'], $message->results()->all()[0]->data());
        $this->assertFalse($message->results()->all()[0]->isFailure());
        $this->assertSame(MessageAHandler::class, $message->results()->all()[0]->handler()?->class());
        $this->assertNull($message->results()->all()[0]->handler()?->description());
    }

    /**
     * @test
     */
    public function stores_long_descriptions(): void
    {
        $description = \str_repeat('a', 300);
        $bus = $this->bus();
        $bus->dispatch(new MessageA(), [new DescriptionStamp($description)]);

        $transport = $this->transport();
        $transport->processOrFail(1);

        $message = ProcessedMessageFactory::first();
        $this->assertSame($description, $message->description());
    }

    /**
     * @test
     */
    public function flow_for_single_handler_failure(): void
    {
        ProcessedMessageFactory::assert()->empty();

        $this->bus()->dispatch(new MessageA(return: 'foo', throw: true));

        $this->transport()->processOrFail(1);

        ProcessedMessageFactory::assert()->count(1);

        $message = ProcessedMessageFactory::first();
        $this->assertSame(MessageA::class, $message->type()->class());
        $this->assertTrue($message->isFailure());
        $this->assertSame(HandlerFailedException::class, $message->failure()?->class());
        $this->assertSame(\sprintf('Handling "%s" failed: error', MessageA::class), $message->failure()->description());
        $this->assertCount(1, $message->results());
        $this->assertTrue($message->results()->all()[0]->isFailure());
        $this->assertSame(\RuntimeException::class, $message->results()->all()[0]->failure()?->class());
        $this->assertSame('error', $message->results()->all()[0]->failure()->description());
        $this->assertSame(MessageAHandler::class, $message->results()->all()[0]->handler()?->class());
        $this->assertSame(['stack_trace'], \array_keys($message->results()->all()[0]->data()));
    }

    /**
     * @test
     */
    public function flow_for_missing_handler(): void
    {
        ProcessedMessageFactory::assert()->empty();

        $this->bus()->dispatch(new MessageB());

        $this->transport()->processOrFail(1);

        ProcessedMessageFactory::assert()->count(1);

        $message = ProcessedMessageFactory::first();

        $this->assertTrue($message->isFailure());
        $this->assertSame(NoHandlerForMessageException::class, $message->failure()?->class());
        $this->assertSame(\sprintf('No handler for message "%s".', MessageB::class), $message->failure()->description());
        $this->assertEmpty($message->results()->all());
    }

    /**
     * @test
     */
    public function flow_for_multiple_handlers(): void
    {
        ProcessedMessageFactory::assert()->empty();

        $this->bus()->dispatch(new MessageC(return1: 'foo', return2: 'bar'));

        $this->transport()->processOrFail(1);

        ProcessedMessageFactory::assert()->count(1);

        $message = ProcessedMessageFactory::first();
        $this->assertSame(MessageC::class, $message->type()->class());
        $this->assertSame('async', $message->transport());
        $this->assertFalse($message->isFailure());
        $this->assertCount(2, $message->results());

        $this->assertSame(['data' => 'foo'], $message->results()->all()[0]->data());
        $this->assertFalse($message->results()->all()[0]->isFailure());
        $this->assertSame(MessageCHandler1::class, $message->results()->all()[0]->handler()?->class());
        $this->assertNull($message->results()->all()[0]->handler()?->description());

        $this->assertSame(['data' => 'bar'], $message->results()->all()[1]->data());
        $this->assertFalse($message->results()->all()[1]->isFailure());
        $this->assertSame(MessageCHandler2::class, $message->results()->all()[1]->handler()?->class());
        $this->assertSame('handle', $message->results()->all()[1]->handler()?->description());
    }

    /**
     * @test
     */
    public function flow_for_multiple_handlers_one_fails(): void
    {
        $this->bus()->dispatch(new MessageC(return1: 'foo', return2: 'bar', throw: true));

        $this->transport()->processOrFail(1);

        ProcessedMessageFactory::assert()->count(1);

        $message = ProcessedMessageFactory::first();
        $this->assertSame(MessageC::class, $message->type()->class());
        $this->assertSame('async', $message->transport());
        $this->assertTrue($message->isFailure());

        $this->assertSame(HandlerFailedException::class, $message->failure()?->class());
        $this->assertSame(\sprintf('Handling "%s" failed: error', MessageC::class), $message->failure()->description());
        $this->assertCount(2, $message->results());

        $this->assertSame(['data' => 'bar'], $message->results()->all()[0]->data());
        $this->assertFalse($message->results()->all()[0]->isFailure());
        $this->assertSame(MessageCHandler2::class, $message->results()->all()[0]->handler()?->class());
        $this->assertSame('handle', $message->results()->all()[0]->handler()?->description());

        $this->assertTrue($message->results()->all()[1]->isFailure());
        $this->assertSame(MessageCHandler1::class, $message->results()->all()[1]->handler()?->class());
        $this->assertNull($message->results()->all()[1]->handler()?->description());
        $this->assertSame(\RuntimeException::class, $message->results()->all()[1]->failure()?->class());
        $this->assertSame('error', $message->results()->all()[1]->failure()->description());
        $this->assertSame(['stack_trace'], \array_keys($message->results()->all()[1]->data()));
    }
}
