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
use Zenstruck\Messenger\Monitor\Twig\Component;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
#[AsLiveComponent(
    name: 'zenstruck_messenger_monitor_transports',
    template: '@ZenstruckMessengerMonitor/components/transports.html.twig',
)]
class TransportsComponent extends Component
{
}
