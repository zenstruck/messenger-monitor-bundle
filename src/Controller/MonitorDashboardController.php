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

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\TransportMonitor;
use Zenstruck\Messenger\Monitor\WorkerMonitor;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
abstract class MonitorDashboardController extends AbstractController
{
    public function __invoke(WorkerMonitor $workers, TransportMonitor $transports, Storage $storage): Response
    {
        return $this->render($this->dashboardTemplate(), [
            'workers' => $workers,
            'transports' => $transports,
            'storage' => $storage,
        ]);
    }

    protected function dashboardTemplate(): string
    {
        return '@ZenstruckMessengerMonitor/bootstrap5/dashboard.html.twig';
    }
}
