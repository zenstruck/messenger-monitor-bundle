<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\History\Model;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;
use Zenstruck\Messenger\Monitor\History\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\History\Stamp\ResultStamp;
use Zenstruck\Messenger\Monitor\Stamp\DescriptionStamp;
use Zenstruck\Messenger\Monitor\Stamp\Tag;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Stub\StringableObject;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class ProcessedMessageTest extends TestCase
{
    use ClockSensitiveTrait;

    /**
     * @test
     */
    public function minimal_constructor(): void
    {
        $start = self::mockTime()->now()->getTimestamp();
        $stamp = new MonitorStamp();

        Clock::get()->sleep(1);

        $stamp = $stamp->markReceived('foo');

        $envelope = new Envelope(new \stdClass(), [$stamp]);

        Clock::get()->sleep(2);

        $message = new class($envelope) extends ProcessedMessage {
            public function id(): string|int|null
            {
                return null;
            }
        };

        $this->assertSame($stamp->runId(), $message->runId());
        $this->assertSame(1, $message->attempt());
        $this->assertSame(\stdClass::class, (string) $message->type());
        $this->assertNull($message->description());
        $this->assertSame($start, $message->dispatchedAt()->getTimestamp());
        $this->assertSame($start + 1, $message->receivedAt()->getTimestamp());
        $this->assertSame($start + 3, $message->finishedAt()->getTimestamp());
        $this->assertSame([], $message->tags()->all());
        $this->assertSame([], $message->results()->all());
        $this->assertSame('foo', $message->transport());
        $this->assertSame(1, $message->timeInQueue());
        $this->assertSame(2, $message->timeToHandle());
        $this->assertSame(3, $message->timeToProcess());
        $this->assertFalse($message->isFailure());
        $this->assertNull($message->failure());
        $this->assertTrue($message->memoryUsage()->isGreaterThan(0));
    }

    /**
     * @test
     */
    public function full_constructor(): void
    {
        $envelope = new Envelope(new StringableObject(), [
            (new MonitorStamp())->markReceived('foo'),
            new RedeliveryStamp(2),
            new ResultStamp([['exception' => \RuntimeException::class, 'message' => 'failure', 'data' => []]]),
            new Tag('foo'),
            new Tag('bar'),
            new Tag('bar'),
            new Tag('baz'),
            new Tag('qux'),
        ]);

        $message = new class($envelope, new \RuntimeException('fail')) extends ProcessedMessage {
            public function id(): string|int|null
            {
                return null;
            }
        };

        $this->assertSame(StringableObject::class, $message->type()->class());
        $this->assertSame('string value', $message->description());
        $this->assertSame(3, $message->attempt());
        $this->assertSame(['foo', 'bar', 'baz', 'qux'], $message->tags()->all());
        $this->assertSame([['exception' => \RuntimeException::class, 'message' => 'failure', 'data' => []]], $message->results()->jsonSerialize());
        $this->assertTrue($message->isFailure());
        $this->assertSame('RuntimeException', (string) $message->failure());
        $this->assertSame('fail', $message->failure()->description());
    }

    /**
     * @test
     */
    public function monitor_stamp_required(): void
    {
        $this->expectException(\LogicException::class);

        new class(new Envelope(new \stdClass())) extends ProcessedMessage {
            public function id(): string|int|null
            {
                return null;
            }
        };
    }

    /**
     * @test
     */
    public function can_add_description_with_stamp(): void
    {
        $envelope = new Envelope(new StringableObject(), [
            (new MonitorStamp())->markReceived('foo'),
            new DescriptionStamp('description value'),
        ]);

        $message = new class($envelope) extends ProcessedMessage {
            public function id(): string|int|null
            {
                return null;
            }
        };

        $this->assertSame(StringableObject::class, $message->type()->class());
        $this->assertSame('description value', $message->description());
    }
}
