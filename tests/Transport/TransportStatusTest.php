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
use Symfony\Component\Messenger\Transport\TransportInterface;
use Zenstruck\Messenger\Monitor\Transport\TransportStatus;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class TransportStatusTest extends TestCase
{
    /**
     * @test
     */
    public function not_countable(): void
    {
        $transport = new TransportStatus('foo', $this->createMock(TransportInterface::class));

        $this->assertFalse($transport->isCountable());

        $this->expectException(\LogicException::class);

        $transport->count();
    }

    /**
     * @test
     */
    public function not_listable(): void
    {
        $transport = new TransportStatus('foo', $this->createMock(TransportInterface::class));

        $this->assertFalse($transport->isListable());

        $this->expectException(\LogicException::class);

        \iterator_to_array($transport->list());
    }
}
