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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\ScheduleMonitor;
use Zenstruck\Messenger\Monitor\TransportMonitor;
use Zenstruck\Messenger\Monitor\WorkerMonitor;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
abstract class MonitorDashboardController extends AbstractController
{
    public function __invoke(
        WorkerMonitor $workers,
        TransportMonitor $transports,
        ?Storage $storage = null,
        ?ScheduleMonitor $schedules = null,
        ?DateTimeFormatter $knp = null, // @phpstan-ignore-line
    ): Response {
        if (!$storage) {
            throw new \LogicException('Storage must be configured to use the dashboard.');
        }

        return $this->render('@ZenstruckMessengerMonitor/dashboard.html.twig', [
            'workers' => $workers,
            'transports' => $transports,
            'snapshot' => Specification::new()->from(Specification::ONE_DAY_AGO)->snapshot($storage),
            'messages' => Specification::new()->without('schedule')->snapshot($storage)->messages(),
            'schedules' => $schedules,
            'knp' => $knp,
        ]);
    }
}
