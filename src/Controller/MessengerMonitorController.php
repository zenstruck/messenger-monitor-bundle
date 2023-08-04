<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Controller;

use Knp\Bundle\TimeBundle\DateTimeFormatter;
use Lorisleiva\CronTranslator\CronTranslator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;
use Zenstruck\Messenger\Monitor\History\Period;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\ScheduleMonitor;
use Zenstruck\Messenger\Monitor\Stamp\Tag;
use Zenstruck\Messenger\Monitor\TransportMonitor;
use Zenstruck\Messenger\Monitor\WorkerMonitor;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
abstract class MessengerMonitorController extends AbstractController
{
    #[Route(name: 'zenstruck_messenger_monitor_dashboard')]
    public function dashboard(
        WorkerMonitor $workers,
        TransportMonitor $transports,
        ?Storage $storage = null,
        ?ScheduleMonitor $schedules = null,
        ?DateTimeFormatter $dateTimeFormatter = null,
    ): Response {
        if (!$storage) {
            throw new \LogicException('Storage must be configured to use the dashboard.');
        }

        return $this->render('@ZenstruckMessengerMonitor/dashboard.html.twig', [
            'workers' => $workers,
            'transports' => $transports->excludeSync(),
            'snapshot' => Specification::create(Period::IN_LAST_DAY)->snapshot($storage),
            'messages' => Specification::new()->snapshot($storage)->messages(),
            'schedules' => $schedules,
            'time_formatter' => $dateTimeFormatter,
            'duration_format' => $dateTimeFormatter && \method_exists($dateTimeFormatter, 'formatDuration'),
        ]);
    }

    #[Route('/history', name: 'zenstruck_messenger_monitor_history')]
    public function history(
        Request $request,
        TransportMonitor $transports,
        ?Storage $storage = null,
        ?ScheduleMonitor $schedules = null,
        ?DateTimeFormatter $dateTimeFormatter = null,
    ): Response {
        if (!$storage) {
            throw new \LogicException('Storage must be configured to use the dashboard.');
        }

        $tags = [$request->query->get('tag')];
        $notTags = [];
        $period = Period::parse($request->query->getString('period'));

        match ($schedule = $request->query->get('schedule')) {
            '_exclude' => $notTags[] = 'schedule',
            '_include' => null,
            default => $tags[] = $schedule,
        };

        $specification = Specification::create([ // @phpstan-ignore-line
            'period' => $period,
            'transport' => $request->query->get('transport'),
            'status' => $request->query->get('status'),
            'tags' => \array_filter($tags),
            'not_tags' => $notTags,
            'message_type' => $request->query->get('type'),
        ]);

        return $this->render('@ZenstruckMessengerMonitor/history.html.twig', [
            'periods' => [...Period::inLastCases(), ...Period::absoluteCases()],
            'period' => $period,
            'transports' => $transports->excludeSync(),
            'snapshot' => $specification->snapshot($storage),
            'schedules' => $schedules,
            'time_formatter' => $dateTimeFormatter,
            'duration_format' => $dateTimeFormatter && \method_exists($dateTimeFormatter, 'formatDuration'),
        ]);
    }

    #[Route('/history/{id}', name: 'zenstruck_messenger_monitor_detail')]
    public function detail(
        string $id,
        ?Storage $storage = null,
        ?DateTimeFormatter $dateTimeFormatter = null,
    ): Response {
        if (!$storage) {
            throw new \LogicException('Storage must be configured to use the dashboard.');
        }

        if (!$message = $storage->find($id)) {
            throw $this->createNotFoundException('Message not found.');
        }

        return $this->render('@ZenstruckMessengerMonitor/_detail.html.twig', [
            'message' => $message,
            'time_formatter' => $dateTimeFormatter,
            'duration_format' => $dateTimeFormatter && \method_exists($dateTimeFormatter, 'formatDuration'),
        ]);
    }

    #[Route('/schedules/{name}', name: 'zenstruck_messenger_monitor_schedules', defaults: ['name' => null])]
    public function schedules(
        TransportMonitor $transports,
        ?ScheduleMonitor $schedules = null,
        ?DateTimeFormatter $dateTimeFormatter = null,

        ?string $name = null,
    ): Response {
        if (!$schedules) {
            throw new \LogicException('Scheduler must be configured to use the dashboard.');
        }

        if (!\count($schedules)) {
            throw new \LogicException('No schedules configured.');
        }

        return $this->render('@ZenstruckMessengerMonitor/schedules.html.twig', [
            'schedules' => $schedules,
            'schedule' => $schedules->get($name),
            'transports' => $transports->excludeSync()->excludeSchedules(),
            'time_formatter' => $dateTimeFormatter,
            'duration_format' => $dateTimeFormatter && \method_exists($dateTimeFormatter, 'formatDuration'),
            'cron_humanizer' => new class() {
                public function humanize(TriggerInterface $trigger, CronExpressionTrigger $cron, ?string $locale): string
                {
                    $title = 'Activate humanized version with composer require lorisleiva/cron-translator';
                    $body = (string) $cron;

                    if (\class_exists(CronTranslator::class)) {
                        $title = $body;
                        $body = CronTranslator::translate((string) $cron, $locale ?? 'en');
                    }

                    return \str_replace((string) $cron, \sprintf('<abbr title="%s">%s</abbr>', $title, $body), (string) $trigger);
                }
            },
        ]);
    }

    #[Route('/schedules/{name}/trigger/{id}/{transport}', methods: 'POST', name: 'zenstruck_messenger_monitor_schedule_trigger')]
    public function triggerTask(
        string $name,
        string $id,
        string $transport,
        Request $request,
        ScheduleMonitor $schedules,
        MessageBusInterface $bus,
    ): Response {
        if (!$this->isCsrfTokenValid(\sprintf('trigger-%s-%s', $id, $transport), $request->headers->get('X-CSRF-Token'))) {
            throw new HttpException(419, 'Invalid CSRF token.');
        }

        $task = $schedules->get($name)->task($id);
        $message = $task->get()->getMessage();

        if ($message instanceof RedispatchMessage) {
            $message = $message->envelope;
        }

        $bus->dispatch($message, [
            new Tag('manual'),
            Tag::forSchedule($task),
            new TransportNamesStamp($transport),
        ]);

        return new Response(null, 204);
    }
}
