<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Twig;

use Knp\Bundle\TimeBundle\DateTimeFormatter;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Schedules;
use Zenstruck\Messenger\Monitor\TransportMonitor;
use Zenstruck\Messenger\Monitor\WorkerMonitor;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class ViewHelper
{
    public const ASSET_MAPPER = 'asset_mapper';
    public const ENCORE = 'encore';

    /**
     * @internal
     *
     * @param null|self::ASSET_MAPPER|self::ENCORE $assetManager
     */
    public function __construct(
        public readonly TransportMonitor $transports,
        public readonly WorkerMonitor $workers,
        public readonly ?Storage $storage,
        public readonly ?Schedules $schedules,
        public readonly ?DateTimeFormatter $timeFormatter,
        public readonly ?string $assetManager = null,
    ) {
    }

    public function useLiveComponents(): bool
    {
        return null !== $this->assetManager;
    }

    public function canFormatDuration(): bool
    {
        return $this->timeFormatter && \method_exists($this->timeFormatter, 'formatDuration');
    }
}
