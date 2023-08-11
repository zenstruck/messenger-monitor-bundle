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
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Zenstruck\Messenger\Monitor\History\Period;
use Zenstruck\Messenger\Monitor\History\Snapshot;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\Twig\Component;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
#[AsLiveComponent(
    name: 'zenstruck_messenger_monitor_snapshot',
    template: '@ZenstruckMessengerMonitor/components/snapshot.html.twig',
)]
class SnapshotComponent extends Component
{
    #[LiveProp]
    public string $period;

    #[ExposeInTemplate]
    public function snapshot(): Snapshot
    {
        return Specification::create($this->period())
            ->snapshot($this->helper->storage ?? throw new \LogicException('Storage must be configured to use the snapshot component.'))
        ;
    }

    #[ExposeInTemplate]
    public function subtitle(): string
    {
        return $this->period()->humanize();
    }

    private function period(): Period
    {
        return Period::parseOrFail($this->period ?? throw new \LogicException('Period not set.'));
    }
}
