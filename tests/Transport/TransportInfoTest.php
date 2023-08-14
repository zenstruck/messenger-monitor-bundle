<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Transport;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Stub\CountableTransport;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Stub\ListableTransport;
use Zenstruck\Messenger\Monitor\Transport\QueuedMessage;
use Zenstruck\Messenger\Monitor\Transport\TransportInfo;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Workers;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class TransportInfoTest extends TestCase
{
    /**
     * @test
     */
    public function not_countable(): void
    {
        $transport = new TransportInfo('foo', $this->createMock(TransportInterface::class), $this->workers());

        $this->assertFalse($transport->isCountable());

        $this->expectException(\LogicException::class);

        $transport->count();
    }

    /**
     * @test
     */
    public function can_count(): void
    {
        $transport = new TransportInfo('foo', new CountableTransport(), $this->workers());

        $this->assertTrue($transport->isCountable());
        $this->assertCount(0, $transport);
    }

    /**
     * @test
     */
    public function not_listable(): void
    {
        $transport = new TransportInfo('foo', $this->createMock(TransportInterface::class), $this->workers());

        $this->assertFalse($transport->isListable());

        $this->expectException(\LogicException::class);

        \iterator_to_array($transport->list());
    }

    /**
     * @test
     */
    public function can_list_queued_messages(): void
    {
        $transport = new TransportInfo('foo', new ListableTransport([
            new Envelope(new \stdClass()),
            new Envelope(new \stdClass()),
        ]), $this->workers());

        $this->assertTrue($transport->isListable());

        $this->assertCount(1, \iterator_to_array($transport->list(1)));

        $envelopes = \iterator_to_array($transport->list());

        $this->assertCount(2, $envelopes);

        $this->assertInstanceOf(QueuedMessage::class, $envelopes[0]);
        $this->assertInstanceOf(QueuedMessage::class, $envelopes[1]);
    }

    /**
     * @test
     */
    public function can_list_workers(): void
    {
        $transport = new TransportInfo(
            'foo',
            $this->createMock(TransportInterface::class),
            $this->workers(),
        );

        $this->assertCount(0, $transport->workers());
    }

    private function workers(): Workers
    {
        return new Workers(new WorkerCache(new NullAdapter()));
    }
}

class Dummy1
{
}

class Dummy2 extends Dummy1
{
}

class Dummy3
{
}
