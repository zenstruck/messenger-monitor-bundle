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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Zenstruck\Messenger\Monitor\Controller\MessengerMonitorController;
use Zenstruck\Messenger\Monitor\History\Model\Flow;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Tests\Unit\History\Model\FlowTest;
use Zenstruck\Messenger\Monitor\Transports;
use Zenstruck\Messenger\Monitor\Twig\ViewHelper;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Workers;

use function Zenstruck\collect;

final class FlowDetailsTest extends TestCase
{
    /** @test */
    public function details_show_previous_and_next_messages_and_group_retries(): void
    {
        $before = FlowTest::attempt('before');
        $current = FlowTest::attempt('current', 'before', 1);
        $current['failureType'] = \RuntimeException::class;
        $current['id'] = 2;
        $current['description'] = '<script>alert("unsafe")</script>';
        $retry = FlowTest::attempt('current', 'before', 2);
        $retry['id'] = 3;
        $retry['description'] = $current['description'];
        $after = FlowTest::attempt('after', 'current');
        $flow = new Flow([$after, $current, $before, $retry]);
        $html = $this->render($flow);

        $this->assertSame(1, \mb_substr_count($html, 'aria-current="true"'));
        $this->assertStringContainsString('(current)', $html);
        $this->assertStringContainsString('2 attempts', $html);
        $this->assertStringContainsString('Attempt 1 (viewing)', $html);
        $this->assertStringContainsString('href="/history/before-1"', $html);
        $this->assertStringContainsString('href="/history/after-1"', $html);
        $this->assertStringContainsString('href="/history/3"', $html);
        $this->assertLessThan(\mb_strpos($html, 'aria-current'), \mb_strpos($html, '/history/before-1'));
        $this->assertGreaterThan(\mb_strpos($html, 'aria-current'), \mb_strpos($html, '/history/after-1'));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Other Attempts', $html);
    }

    /** @test */
    public function details_hide_the_list_for_standalone_or_legacy_messages(): void
    {
        $this->assertStringNotContainsString('aria-label="Message flow"', $this->render(new Flow()));
        $this->assertStringNotContainsString('aria-label="Message flow"', $this->render(new Flow([FlowTest::attempt('current')])));
    }

    /**
     * @test
     * @dataProvider messageIdentities
     */
    public function details_fetch_related_history_once_only_for_identified_messages(bool $identity): void
    {
        $storage = $this->createMock(Storage::class);
        $message = $this->message($identity);
        $storage->expects($this->once())->method('find')->with('2')->willReturn($message);
        $storage->expects($this->once())->method('filter')->willReturn(collect([]));

        $storage->expects($identity ? $this->once() : $this->never())->method('flow')
            ->with('flow')->willReturn(new Flow([FlowTest::attempt('before'), FlowTest::attempt('current', 'before')]));

        $controller = new class extends MessengerMonitorController {
            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                return new Response((string) \count($parameters['flow']));
            }
        };
        $response = $controller->detail('2', $this->helper($storage));

        $this->assertSame($identity ? '2' : '0', $response->getContent());
    }

    /** @return iterable<string, array{bool}> */
    public static function messageIdentities(): iterable
    {
        yield 'identified message' => [true];
        yield 'legacy message' => [false];
    }

    private function render(Flow $flow): string
    {
        $message = $this->message();
        $other = $this->message();
        (new \ReflectionProperty($other, 'id'))->setValue($other, 3);
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../templates', 'ZenstruckMessengerMonitor');
        $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('path', static fn(string $route, array $parameters = []) => '/history/'.($parameters['id'] ?? '')));
        $twig->addGlobal('app', ['request' => new Request()]);

        return $twig->render('@ZenstruckMessengerMonitor/detail.html.twig', [
            'message' => $message,
            'flow' => $flow,
            'other_attempts' => [$message, $other],
            'helper' => $this->helper(),
        ]);
    }

    private function message(bool $identity = true): ProcessedMessage
    {
        $stamp = (new MonitorStamp())->markReceived('async')->markFinished();
        $message = new \Zenstruck\Messenger\Monitor\Tests\Fixture\Entity\ProcessedMessage(new Envelope(new \stdClass(), [$stamp]), new Results([]));
        (new \ReflectionProperty($message, 'id'))->setValue($message, 2);

        if ($identity) {
            (new \ReflectionProperty(ProcessedMessage::class, 'messageId'))->setValue($message, 'current');
            (new \ReflectionProperty(ProcessedMessage::class, 'correlationId'))->setValue($message, 'flow');
        }

        return $message;
    }

    private function helper(?Storage $storage = null): ViewHelper
    {
        $workers = new Workers(new WorkerCache(new NullAdapter()));
        $transports = new Transports(new ServiceLocator([]), $workers);

        return new ViewHelper($transports, $workers, $storage, null, null, null);
    }
}
