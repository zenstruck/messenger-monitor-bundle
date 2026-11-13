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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\WorkerMetadata;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Zenstruck\Messenger\Monitor\Tests\Unit\Stub\CountableTransport;
use Zenstruck\Messenger\Monitor\Transports;
use Zenstruck\Messenger\Monitor\Twig\ViewHelper;
use Zenstruck\Messenger\Monitor\Worker\TransportCooldowns;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Worker\WorkerInfo;
use Zenstruck\Messenger\Monitor\Workers;

final class CooldownWidgetsTest extends TestCase
{
    use ClockSensitiveTrait;

    /** @test */
    public function widgets_show_partial_cooldowns_unknown_resume_times_and_expiry(): void
    {
        $clock = self::mockTime();
        $cache = new WorkerCache(new ArrayAdapter());
        $metadata = new WorkerMetadata(['transportNames' => ['async', 'webhooks']]);
        $cache->set(1, $metadata, WorkerInfo::PROCESSING, 7, 1000, cooldowns: new TransportCooldowns([
            'async' => $clock->now()->modify('+30 seconds'),
            'webhooks' => null,
        ]));
        $cache->add(2, $metadata, 3, 1000);
        $workers = new Workers($cache);
        $transports = new Transports(new ServiceLocator([
            'async' => static fn() => new CountableTransport(),
            'webhooks' => static fn() => new CountableTransport(),
        ]), $workers);
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../templates', 'ZenstruckMessengerMonitor');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('path', static fn() => '/monitor'));
        $twig->addGlobal('app', ['request' => new Request()]);
        $parameters = ['workers' => $workers, 'transports' => $transports, 'helper' => new ViewHelper($transports, $workers, null, null, null, null)];
        $workerView = $twig->render('@ZenstruckMessengerMonitor/components/workers.html.twig', $parameters);
        $transportView = $twig->render('@ZenstruckMessengerMonitor/components/transports.html.twig', $parameters);

        $this->assertSame(2, \mb_substr_count($workerView, 'Cooling down'));
        $this->assertStringContainsString('Expected to resume', $workerView);
        $this->assertStringContainsString('Resume time unknown', $workerView);
        $this->assertStringContainsString('processing', $workerView);
        $this->assertSame(2, \mb_substr_count($transportView, '1 of 2 cooling down'));

        $clock->sleep(30);
        $workerView = $twig->render('@ZenstruckMessengerMonitor/components/workers.html.twig', $parameters);
        $transportView = $twig->render('@ZenstruckMessengerMonitor/components/transports.html.twig', $parameters);
        $this->assertStringNotContainsString('Expected to resume', $workerView);
        $this->assertSame(1, \mb_substr_count($workerView, 'Cooling down'));
        $this->assertSame(1, \mb_substr_count($transportView, '1 of 2 cooling down'));
    }

    /** @test */
    public function transport_page_shows_only_the_selected_transports_active_cooldowns(): void
    {
        $clock = self::mockTime();
        $cache = new WorkerCache(new ArrayAdapter());
        $metadata = new WorkerMetadata(['transportNames' => ['async']]);
        $cache->set(1, $metadata, WorkerInfo::IDLE, 0, 1000, cooldowns: new TransportCooldowns(['async' => $clock->now()->modify('+30 seconds')]));
        $cache->add(2, $metadata, 0, 1000);
        $cache->set(3, new WorkerMetadata(['transportNames' => ['other']]), WorkerInfo::IDLE, 0, 1000, cooldowns: new TransportCooldowns(['other' => null]));
        $workers = new Workers($cache);
        $transports = new Transports(new ServiceLocator([
            'async' => static fn() => new CountableTransport(),
            'other' => static fn() => new CountableTransport(),
        ]), $workers);
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../templates', 'ZenstruckMessengerMonitor');
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['@ZenstruckMessengerMonitor/layout.html.twig' => '{% block content %}{% endblock %}']),
            $loader,
        ]), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('path', static fn() => '/monitor'));
        $twig->addGlobal('app', ['request' => new Request()]);
        $parameters = ['transports' => $transports, 'transport' => $transports->get('async'), 'helper' => new ViewHelper($transports, $workers, null, null, null, null), 'limit' => 50];
        $page = $twig->render('@ZenstruckMessengerMonitor/transport.html.twig', $parameters);

        $this->assertStringContainsString('1 of 2 workers cooling down', $page);
        $this->assertStringContainsString('No messages in this queue.', $page);

        $parameters['transport'] = $transports->get('other');
        $page = $twig->render('@ZenstruckMessengerMonitor/transport.html.twig', $parameters);
        $this->assertStringContainsString('1 of 1 workers cooling down', $page);

        $clock->sleep(30);
        $parameters['transport'] = $transports->get('async');
        $page = $twig->render('@ZenstruckMessengerMonitor/transport.html.twig', $parameters);
        $this->assertStringNotContainsString('workers cooling down', $page);
    }
}
