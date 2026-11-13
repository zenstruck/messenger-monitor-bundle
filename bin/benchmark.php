<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Serializer;
use Zenstruck\Messenger\Monitor\EventListener\AddMonitorStampListener;
use Zenstruck\Messenger\Monitor\EventListener\CaptureExecutionListener;
use Zenstruck\Messenger\Monitor\EventListener\ReceiveMonitorStampListener;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\Middleware\SchedulerMonitoringMiddleware;
use Zenstruck\Messenger\Monitor\Serializer\Normalizer\MonitorStampNormalizer;
use Zenstruck\Messenger\Monitor\Stamp\ExecutionStamp;
use Zenstruck\Messenger\Monitor\Stamp\HandlerMetricsStamp;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Entity\ProcessedMessage;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Worker\WorkerListener;

require \dirname(__DIR__).'/vendor/autoload.php';

if (isset($argv[1])) {
    $reference = $argv[1];
    \spl_autoload_register(static function(string $class) use ($reference): void {
        $prefix = 'Zenstruck\\Messenger\\Monitor\\';

        if (!\str_starts_with($class, $prefix) || \str_starts_with($class, $prefix.'Tests\\')) {
            return;
        }
        $path = 'src/'.\str_replace('\\', '/', \mb_substr($class, \mb_strlen($prefix))).'.php';
        $process = new Process(['git', 'show', $reference.':'.$path], \dirname(__DIR__));
        $process->mustRun();
        eval('?>'.$process->getOutput());
    }, true, true);
}

function measure(string $label, Closure $operation, int $iterations = 100000): void
{
    for ($i = 0; $i < 1000; ++$i) {
        $operation();
    }
    $times = [];
    for ($round = 0; $round < 5; ++$round) {
        $start = \hrtime(true);
        for ($i = 0; $i < $iterations; ++$i) {
            $operation();
        }
        $times[] = (\hrtime(true) - $start) / $iterations;
    }
    \sort($times);
    \printf("%-34s %10.1f ns/op\n", $label, $times[2]);
}

$base = new Envelope(new stdClass());
$listener = new AddMonitorStampListener();
measure('dispatch listener', static function() use ($listener, $base) {
    $listener(new SendMessageToTransportsEvent($base, []));
});
$stamp = new MonitorStamp();
$phpSerializer = new PhpSerializer();
$stamped = $base->with($stamp);
measure('PHP encode without monitoring', static fn() => $phpSerializer->encode($base));
measure('PHP encode with monitoring', static fn() => $phpSerializer->encode($stamped));
$data = $stamp->toArray();
measure('export initial stamp', static fn() => $stamp->toArray());
measure('restore stamp', static fn() => MonitorStamp::from($data));
$serializer = new Serializer([new MonitorStampNormalizer(), new DateTimeNormalizer()], [new JsonEncoder()]);
measure('serialize initial stamp', static fn() => $serializer->serialize($stamp, 'json'));
$finished = $stamp->markReceived('async')->markFinished();
measure('serialize finished stamp', static fn() => $serializer->serialize($finished, 'json'));

$sender = new class implements SenderInterface {
    public function send(Envelope $envelope): Envelope
    {
        return $envelope;
    }
};
$senders = new class($sender) implements SendersLocatorInterface {
    public function __construct(private SenderInterface $sender)
    {
    }

    public function getSenders(Envelope $envelope): iterable
    {
        yield 'async' => $this->sender;
    }
};
$sync = new MessageBus([new SchedulerMonitoringMiddleware()]);
measure('sync bus dispatch', static fn() => $sync->dispatch($base));

$events = new EventDispatcher();
$events->addListener(SendMessageToTransportsEvent::class, $listener);
$bus = new MessageBus([new SchedulerMonitoringMiddleware(), new SendMessageMiddleware($senders, $events)]);
measure('async bus dispatch', static fn() => $bus->dispatch($base));

$results = new Results([]);
$finishedEnvelope = $base->with($finished);
measure('history record construction', static fn() => new ProcessedMessage($finishedEnvelope, $results));

$receive = new ReceiveMonitorStampListener([]);
$envelope = $base->with($stamp);
measure('receive monitored', static function() use ($receive, $envelope) {
    $receive(new WorkerMessageReceivedEvent($envelope, 'async'));
});
measure('receive unmonitored', static function() use ($receive, $base) {
    $receive(new WorkerMessageReceivedEvent($base, 'async'));
});
$capture = new CaptureExecutionListener();
$executionEnvelope = $base->with(new ExecutionStamp(), new HandlerMetricsStamp());
$event = (object) ['envelope' => $executionEnvelope, 'handlerDescriptor' => new HandlerDescriptor(static fn() => null)];
measure('handler start and finish', static function() use ($capture, $event) {
    $capture->onStart($event);
    $capture($event);
});
measure('finish monitor execution', static fn() => $executionEnvelope->last(ExecutionStamp::class)->finishMonitor($stamp->markReceived('async')));

foreach (['array' => new ArrayAdapter(), 'filesystem' => new FilesystemAdapter('monitor-perf-'.\getmypid(), 3600, \sys_get_temp_dir().'/messenger-monitor-perf-'.\getmypid())] as $name => $pool) {
    $workerListener = new WorkerListener(new WorkerCache($pool));
    $worker = new Worker([], new MessageBus());
    $workerListener->onStart(new WorkerStartedEvent($worker));
    $received = new WorkerMessageReceivedEvent($envelope, 'async');
    $handled = new WorkerMessageHandledEvent($envelope, 'async');
    $running = new WorkerRunningEvent($worker, false);
    measure('worker lifecycle '.$name, static function() use ($workerListener, $received, $handled, $running) {
        $workerListener->onMessageReceived($received);
        $workerListener->onMessageFinished($handled);
        $workerListener->onRunning($running);
    }, 'array' === $name ? 10000 : 1000);
    $workerListener->onStop();
    $pool->clear();
}
