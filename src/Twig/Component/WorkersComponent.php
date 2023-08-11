<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Twig\Component;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Zenstruck\Messenger\Monitor\Twig\Component;
use Zenstruck\Messenger\Monitor\WorkerMonitor;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
#[AsLiveComponent(
    name: 'zenstruck_messenger_monitor_workers',
    template: '@ZenstruckMessengerMonitor/components/workers.html.twig',
)]
class WorkersComponent extends Component
{
    #[ExposeInTemplate]
    public function workers(): WorkerMonitor
    {
        return $this->helper->workers;
    }
}
