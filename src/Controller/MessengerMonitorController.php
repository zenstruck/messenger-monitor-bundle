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
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
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
        return $this->render('@ZenstruckMessengerMonitor/dashboard.html.twig', [
            'helper' => $helper,
            'snapshot' => Specification::create(Period::IN_LAST_DAY)->snapshot($helper->storage()),
            'messages' => Specification::new()->snapshot($helper->storage())->messages(),
        ]);
    }

    #[Route('/statistics', name: 'zenstruck_messenger_monitor_statistics')]
    public function statistics(
        Request $request,
        ViewHelper $helper,
    ): Response {
        $period = Period::parse($request->query->getString('period'));
        $specification = Specification::create([ // @phpstan-ignore-line
            'period' => $period,
        ]);

        return $this->render('@ZenstruckMessengerMonitor/statistics.html.twig', [
            'helper' => $helper,
            'periods' => [...Period::inLastCases(), ...Period::absoluteCases()],
            'period' => $period,
            'metrics' => $specification->snapshot($helper->storage())->perMessageTypeMetrics(),
        ]);
    }

    #[Route('/history', name: 'zenstruck_messenger_monitor_history')]
    public function history(
        Request $request,
        ViewHelper $helper,
    ): Response {
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
            'snapshot' => $specification->snapshot($helper->storage()),
            'filters' => $specification->filters($helper->storage()),
        ]);
    }

    #[Route('/history/{id}', name: 'zenstruck_messenger_monitor_detail')]
    public function detail(string $id, ViewHelper $helper): Response
    {
        if (!$message = $helper->storage()->find($id)) {
            throw $this->createNotFoundException('Message not found.');
        }

        return $this->render('@ZenstruckMessengerMonitor/detail.html.twig', [
            'helper' => $helper,
            'message' => $message,
            'other_attempts' => $helper->storage()->filter(Specification::create(['run_id' => $message->runId()])),
        ]);
    }

    #[Route('/transport/{name}', name: 'zenstruck_messenger_monitor_transport', defaults: ['name' => null])]
    public function transports(
        ViewHelper $helper,
        Request $request,
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
            'limit' => $request->query->getInt('limit', 50),
        ]);
    }

    #[Route('/transport/{name}/{id}/remove', name: 'zenstruck_messenger_monitor_transport_remove', methods: 'POST')]
    public function removeTransportMessage(
        string $name,
        string $id,
        Request $request,
        ViewHelper $helper,
    ): Response {
        $helper->validateCsrfToken($request->request->getString('_token'), 'remove', $id, $name);

        $transport = $helper->transports->get($name);
        $message = $transport->find($id) ?? throw $this->createNotFoundException('Message not found.');

        $transport->get()->reject($message->envelope());

        $this->addFlash('success', \sprintf('Message "%s" removed from transport "%s".', $message->message()->shortName(), $name));

        return $this->redirectToRoute('zenstruck_messenger_monitor_transport', ['name' => $name]);
    }

    #[Route('/transport/{name}/{id}/retry', name: 'zenstruck_messenger_monitor_transport_retry', methods: 'POST')]
    public function retryFailedMessage(
        string $name,
        string $id,
        Request $request,
        ViewHelper $helper,
        MessageBusInterface $bus,
    ): Response {
        $helper->validateCsrfToken($request->request->getString('_token'), 'retry', $id, $name);

        $transport = $helper->transports->get($name);
        $message = $transport->find($id) ?? throw $this->createNotFoundException('Message not found.');
        $originalTransportName = $message->envelope()->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName() ?? throw $this->createNotFoundException('Original transport not found.');

        // Remove failure-related stamps to allow the message to be sent to the failure transport again if it fails
        $envelope = $message->envelope()
            ->withoutAll(SentToFailureTransportStamp::class)
            ->withoutAll(RedeliveryStamp::class)
            ->withoutAll(ErrorDetailsStamp::class);

        $bus->dispatch($envelope, [
            new TagStamp('retry'),
            new TagStamp('manual'),
        ]);
        $transport->get()->reject($envelope);

        $this->addFlash('success', \sprintf('Retrying message "%s" on transport "%s".', $message->message()->shortName(), $originalTransportName));

        return $this->redirectToRoute('zenstruck_messenger_monitor_transport', ['name' => $name]);
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
                public function humanize(TriggerInterface $trigger, CronExpressionTrigger $cron): string
                {
                    $title = 'Activate humanized version with composer require lorisleiva/cron-translator';
                    $body = (string) $cron;

                    if (\class_exists(CronTranslator::class)) {
                        $title = $body;
                        $body = CronTranslator::translate((string) $cron);
                    }

                    return \str_replace((string) $cron, \sprintf('<abbr title="%s">%s</abbr>', $title, $body), (string) $trigger);
                }
            },
        ]);
    }

    #[Route('/schedules/{name}/trigger/{id}/{transport}', methods: 'POST', name: 'zenstruck_messenger_monitor_schedule_trigger')]
    public function triggerScheduleTask(
        string $name,
        string $id,
        string $transport,
        Request $request,
        Schedules $schedules,
        MessageBusInterface $bus,
        ViewHelper $helper,
    ): Response {
        $helper->validateCsrfToken($request->request->getString('_token'), 'trigger', $id, $transport);

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

        $this->addFlash('success', \sprintf('Task "%s" triggered on "%s" transport.', $task->id(), $transport));

        return $this->redirectToRoute('zenstruck_messenger_monitor_schedule', ['name' => $name]);
    }

    #[Route('/_workers', name: 'zenstruck_messenger_monitor_workers_widget')]
    public function workersWidget(
        ViewHelper $helper,
    ): Response {
        return $this->render('@ZenstruckMessengerMonitor/components/workers.html.twig', [
            'workers' => $helper->workers,
        ]);
    }

    #[Route('/_transports', name: 'zenstruck_messenger_monitor_transports_widget')]
    public function transportsWidget(
        ViewHelper $helper,
    ): Response {
        return $this->render('@ZenstruckMessengerMonitor/components/transports.html.twig', [
            'transports' => $helper->transports,
        ]);
    }

    #[Route('/_snapshot', name: 'zenstruck_messenger_monitor_snapshot_widget')]
    public function snapshotWidget(
        ViewHelper $helper,
    ): Response {
        return $this->render('@ZenstruckMessengerMonitor/components/snapshot.html.twig', [
            'helper' => $helper,
            'snapshot' => Specification::create(Period::IN_LAST_DAY)->snapshot($helper->storage()),
            'subtitle' => 'Last 24 Hours',
        ]);
    }

    #[Route('/_recent-messages', name: 'zenstruck_messenger_monitor_recent_messages_widget')]
    public function recentMessagesWidget(
        ViewHelper $helper,
    ): Response {
        return $this->render('@ZenstruckMessengerMonitor/components/recent_messages.html.twig', [
            'messages' => Specification::new()->snapshot($helper->storage())->messages(),
            'helper' => $helper,
        ]);
    }
}
