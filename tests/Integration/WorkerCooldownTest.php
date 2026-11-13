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
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Cooldown\CooldownStatus;
use Symfony\Component\Messenger\Cooldown\CooldownStrategyInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Zenstruck\Messenger\Monitor\Worker\CooldownStrategyLocator;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Worker\WorkerListener;

final class WorkerCooldownTest extends TestCase
{
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        if (!\interface_exists(CooldownStrategyInterface::class)) {
            $this->markTestSkipped('Requires Symfony 8.2 transport cooldowns.');
        }
    }

    /** @test */
    public function an_indefinite_cooldown_clears_when_an_empty_transport_resumes_polling(): void
    {
        $cache = new WorkerCache(new ArrayAdapter());
        $listener = new WorkerListener($cache);
        $strategy = $this->createMock(CooldownStrategyInterface::class);
        $strategy->expects($this->exactly(2))->method('checkStatus')->with('async')->willReturnOnConsecutiveCalls(CooldownStatus::indefinite(), CooldownStatus::inactive());
        $locator = new CooldownStrategyLocator(new ServiceLocator(['async' => static fn() => $strategy]), $listener);
        $this->assertTrue($locator->has('async'));
        $this->assertFalse($locator->has('other'));
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerStartedEvent::class, $listener->onStart(...));
        $dispatcher->addListener(WorkerStoppedEvent::class, $listener->onStop(...));
        $dispatcher->addListener(WorkerRunningEvent::class, $listener->onRunning(...));
        $observed = [];
        $dispatcher->addListener(WorkerRunningEvent::class, static function(WorkerRunningEvent $event) use ($cache, &$observed): void {
            $workers = \iterator_to_array($cache);
            $observed[] = null !== $workers[0]->cooldownFor('async');

            if (2 === \count($observed)) {
                $event->getWorker()->stop();
            }
        });
        $worker = new Worker(['async' => new InMemoryTransport()], new MessageBus(), $dispatcher, cooldownStrategies: ['async' => $locator->get('async')]);
        $worker->run(['sleep' => 0]);

        $this->assertSame([true, false], $observed);
        $this->assertCount(0, \iterator_to_array($cache));
    }

    /** @test */
    public function observes_updated_status_without_rechecking_or_changing_strategy_outcomes(): void
    {
        $clock = self::mockTime();
        $cache = new WorkerCache(new ArrayAdapter());
        $listener = new WorkerListener($cache);
        $listener->onStart(new WorkerStartedEvent(new Worker([], new MessageBus())));
        $first = CooldownStatus::until($clock->now()->modify('+1 second'));
        $extended = CooldownStatus::until($clock->now()->modify('+1 minute'));
        $strategy = $this->createMock(CooldownStrategyInterface::class);
        $strategy->expects($this->exactly(2))->method('checkStatus')->willReturnOnConsecutiveCalls($first, $extended);
        $envelope = new Envelope(new \stdClass());
        $error = new \RuntimeException('Broker unavailable.');
        $strategy->expects($this->once())->method('recordSuccess')->with('async', $envelope);
        $strategy->expects($this->once())->method('recordFailure')->with('async', null, $error);
        $locator = new CooldownStrategyLocator(new ServiceLocator(['async' => static fn() => $strategy]), $listener);
        $monitoring = $locator->get('async');

        $this->assertSame($first, $monitoring->checkStatus('async'));
        $this->assertSame($extended, $monitoring->checkStatus('async'));
        $workers = \iterator_to_array($cache);
        $this->assertEquals($extended->getEnd(), $workers[0]->cooldownFor('async')->endsAt());
        $monitoring->recordSuccess('async', $envelope);
        $monitoring->recordFailure('async', null, $error);
    }
}
