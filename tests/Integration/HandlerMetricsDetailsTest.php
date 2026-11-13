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
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Entity\ProcessedMessage;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageAHandler;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageCHandler1;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageCHandler2;
use Zenstruck\Messenger\Monitor\Transports;
use Zenstruck\Messenger\Monitor\Twig\ViewHelper;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Workers;

final class HandlerMetricsDetailsTest extends TestCase
{
    /** @test */
    public function details_show_measurements_with_each_handlers_own_result(): void
    {
        $html = $this->render(new Results([
            ['handler' => MessageAHandler::class, 'data' => ['output' => 'Created invoice'], 'duration_ms' => 125, 'memory_change' => 2097152],
            ['handler' => MessageCHandler1::class, 'exception' => \RuntimeException::class, 'message' => '<failed>', 'data' => [], 'duration_ms' => 4, 'memory_change' => -1048576],
            ['handler' => MessageCHandler2::class, 'data' => [], 'duration_ms' => 0, 'memory_change' => 0],
        ]));

        $this->assertSame(3, \mb_substr_count($html, 'aria-label="Handler measurements"'));
        $this->assertStringContainsString('125 ms', $html);
        $this->assertStringContainsString('4 ms', $html);
        $this->assertStringContainsString('0 ms', $html);
        $this->assertStringContainsString('+2.10 MB', $html);
        $this->assertStringContainsString('-1.05 MB', $html);
        $this->assertStringContainsString('0 B', $html);
        $this->assertStringContainsString('Created invoice', $html);
        $this->assertStringContainsString('&lt;failed&gt;', $html);
        $this->assertStringContainsString('Time to Handle', $html);
    }

    /** @test */
    public function details_hide_unavailable_measurements_without_losing_results(): void
    {
        $html = $this->render(new Results([['handler' => MessageAHandler::class, 'data' => ['output' => 'Legacy result']]]));

        $this->assertStringNotContainsString('aria-label="Handler measurements"', $html);
        $this->assertStringNotContainsString('Memory change', $html);
        $this->assertStringContainsString('Legacy result', $html);
    }

    private function render(Results $results): string
    {
        $message = new ProcessedMessage(new Envelope(new \stdClass(), [(new MonitorStamp())->markReceived('async')->markFinished()]), $results);
        $workers = new Workers(new WorkerCache(new NullAdapter()));
        $transports = new Transports(new ServiceLocator([]), $workers);
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../templates', 'ZenstruckMessengerMonitor');
        $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('path', static fn() => '/history'));
        $twig->addGlobal('app', ['request' => new Request()]);

        return $twig->render('@ZenstruckMessengerMonitor/detail.html.twig', [
            'message' => $message,
            'other_attempts' => [],
            'helper' => new ViewHelper($transports, $workers, null, null, null, null),
        ]);
    }
}
