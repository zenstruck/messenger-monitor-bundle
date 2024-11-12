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

use Lorisleiva\CronTranslator\CronTranslator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;
use Zenstruck\Messenger\Monitor\History\Period;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\Schedules;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;
use Zenstruck\Messenger\Monitor\Twig\ViewHelper;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
abstract class MessengerMonitorController extends AbstractController
{
    #[Route(name: 'zenstruck_messenger_monitor_dashboard')]
    public function dashboard(ViewHelper $helper): Response
    {
        if (!$helper->storage) {
            throw new \LogicException('Storage must be configured to use the dashboard.');
        }

        return $this->render('@ZenstruckMessengerMonitor/dashboard.html.twig', [
            'helper' => $helper,
            'snapshot' => Specification::create(Period::IN_LAST_DAY)->snapshot($helper->storage),
            'messages' => Specification::new()->snapshot($helper->storage)->messages(),
        ]);
    }

    #[Route('/statistics', name: 'zenstruck_messenger_monitor_statistics')]
    public function statistics(
        Request $request,
        ViewHelper $helper,
    ): Response {
        if (!$helper->storage) {
            throw new \LogicException('Storage must be configured to use the dashboard.');
        }

        $period = Period::parse($request->query->getString('period'));
        $specification = Specification::create([ // @phpstan-ignore-line
            'period' => $period,
        ]);

        return $this->render('@ZenstruckMessengerMonitor/statistics.html.twig', [
            'helper' => $helper,
            'periods' => [...Period::inLastCases(), ...Period::absoluteCases()],
            'period' => $period,
            'metrics' => $specification->snapshot($helper->storage)->perMessageTypeMetrics(),
        ]);
    }

    #[Route('/history', name: 'zenstruck_messenger_monitor_history')]
    public function history(
        Request $request,
        ViewHelper $helper,
    ): Response {
        if (!$helper->storage) {
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
            'helper' => $helper,
            'periods' => [...Period::inLastCases(), ...Period::absoluteCases()],
            'period' => $period,
            'snapshot' => $specification->snapshot($helper->storage),
            'filters' => $specification->filters($helper->storage),
        ]);
    }

    #[Route('/history/{id}', name: 'zenstruck_messenger_monitor_detail')]
    public function detail(string $id, ViewHelper $helper): Response
    {
        if (!$helper->storage) {
            throw new \LogicException('Storage must be configured to use the dashboard.');
        }

        if (!$message = $helper->storage->find($id)) {
            throw $this->createNotFoundException('Message not found.');
        }

        return $this->render('@ZenstruckMessengerMonitor/detail.html.twig', [
            'helper' => $helper,
            'message' => $message,
            'other_attempts' => $helper->storage->filter(Specification::create(['run_id' => $message->runId()])),
        ]);
    }

    #[Route('/transport/{name}', name: 'zenstruck_messenger_monitor_transport', defaults: ['name' => null])]
    public function transports(
        ViewHelper $helper,

        ?string $name = null,
    ): Response {
        $countable = $helper->transports->filter()->countable();

        if (!\count($countable)) {
            throw new \LogicException('No countable transports configured.');
        }

        if (!$name) {
            $name = $countable->names()[0];
        }

        return $this->render('@ZenstruckMessengerMonitor/transport.html.twig', [
            'helper' => $helper,
            'transports' => $countable,
            'transport' => $helper->transports->get($name),
        ]);
    }

    #[Route('/schedule/{name}', name: 'zenstruck_messenger_monitor_schedule', defaults: ['name' => null])]
    public function schedules(
        ViewHelper $helper,

        ?string $name = null,
    ): Response {
        if (!$helper->schedules) {
            throw new \LogicException('Scheduler must be configured to use the dashboard.');
        }

        if (!\count($helper->schedules)) {
            throw new \LogicException('No schedules configured.');
        }

        return $this->render('@ZenstruckMessengerMonitor/schedule.html.twig', [
            'helper' => $helper,
            'schedules' => $helper->schedules,
            'schedule' => $helper->schedules->get($name),
            'transports' => $helper->transports->filter()->excludeSync()->excludeSchedules()->excludeFailed(),
            'cron_humanizer' => new class {
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
        Schedules $schedules,
        MessageBusInterface $bus,
    ): Response {
        if (!$this->isCsrfTokenValid(\sprintf('trigger-%s-%s', $id, $transport), $request->headers->get('X-CSRF-Token'))) {
            throw new HttpException(419, 'Invalid CSRF token.');
        }

        $task = $schedules->get($name)->task($id);

        $context = new MessageContext(
            $schedules->get($name)->name(),
            $task->id(),
            $task->trigger()->get(),
            new \DateTimeImmutable(),
        );

        foreach ($task->get()->getMessages($context) as $message) {
            if ($message instanceof RedispatchMessage) {
                $message = $message->envelope;
            }

            $bus->dispatch($message, [
                new TagStamp('manual'),
                TagStamp::forSchedule($task),
                new TransportNamesStamp($transport),
            ]);
        }

        return new Response(null, 204);
    }
}
