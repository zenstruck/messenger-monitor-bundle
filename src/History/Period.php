<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\History;

use function Symfony\Component\Clock\now;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
enum Period: string
{
    case IN_LAST_HOUR = 'in-last-hour';
    case IN_LAST_DAY = 'in-last-day';
    case IN_LAST_WEEK = 'in-last-week';
    case IN_LAST_MONTH = 'in-last-month';
    case TODAY = 'today';
    case YESTERDAY = 'yesterday';
    case LAST_WEEK = 'last-week';
    case LAST_MONTH = 'last-month';
    case ALL = 'all';
    case OLDER_THAN_1_HOUR = '1-hour';
    case OLDER_THAN_1_DAY = '1-day';
    case OLDER_THAN_1_WEEK = '1-week';
    case OLDER_THAN_1_MONTH = '1-month';

    public static function parse(string|self|null $value, self $default = self::IN_LAST_DAY): self
    {
        try {
            return self::parseOrFail($value);
        } catch (\InvalidArgumentException) {
            return $default;
        }
    }

    public static function parseOrFail(string|self|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        try {
            return self::from((string) $value);
        } catch (\ValueError $e) {
            throw new \InvalidArgumentException(\sprintf('Invalid period "%s".', $value), previous: $e);
        }
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return \array_map(static fn(self $p) => $p->value, self::cases());
    }

    /**
     * @return self[]
     */
    public static function inLastCases(): array
    {
        return [
            self::IN_LAST_HOUR,
            self::IN_LAST_DAY,
            self::IN_LAST_WEEK,
            self::IN_LAST_MONTH,
        ];
    }

    /**
     * @return string[]
     */
    public static function inLastValues(): array
    {
        return \array_map(static fn(self $p) => $p->value, self::inLastCases());
    }

    /**
     * @return self[]
     */
    public static function absoluteCases(): array
    {
        return [
            self::TODAY,
            self::YESTERDAY,
            self::LAST_WEEK,
            self::LAST_MONTH,
            self::ALL,
        ];
    }

    /**
     * @return string[]
     */
    public static function absoluteValues(): array
    {
        return \array_map(static fn(self $p) => $p->value, self::absoluteCases());
    }

    /**
     * @return self[]
     */
    public static function olderThanCases(): array
    {
        return [
            self::OLDER_THAN_1_HOUR,
            self::OLDER_THAN_1_DAY,
            self::OLDER_THAN_1_WEEK,
            self::OLDER_THAN_1_MONTH,
        ];
    }

    /**
     * @return string[]
     */
    public static function olderThanValues(): array
    {
        return \array_map(static fn(self $p) => $p->value, self::olderThanCases());
    }

    public function humanize(): string
    {
        return match ($this) {
            self::IN_LAST_HOUR => 'In Last Hour',
            self::IN_LAST_DAY => 'In Last Day',
            self::IN_LAST_WEEK => 'In Last Week',
            self::IN_LAST_MONTH => 'In Last Month',
            self::TODAY => 'Today',
            self::YESTERDAY => 'Yesterday',
            self::LAST_WEEK => 'Last Week',
            self::LAST_MONTH => 'Last Month',
            self::ALL => 'All Time',
            self::OLDER_THAN_1_HOUR => 'Older Than 1 Hour',
            self::OLDER_THAN_1_DAY => 'Older Than 1 Day',
            self::OLDER_THAN_1_WEEK => 'Older Than 1 Week',
            self::OLDER_THAN_1_MONTH => 'Older Than 1 Month',
        };
    }

    /**
     * From, to.
     *
     * @return array{?\DateTimeImmutable, ?\DateTimeImmutable}
     */
    public function timestamps(): array
    {
        $now = now();

        return match ($this) {
            self::IN_LAST_HOUR => [$now->modify('-1 hour'), null],
            self::IN_LAST_DAY => [$now->modify('-24 hours'), null],
            self::IN_LAST_WEEK => [$now->modify('-7 days'), null],
            self::IN_LAST_MONTH => [$now->modify('-1 month'), null],
            self::TODAY => [new \DateTimeImmutable('today'), new \DateTimeImmutable('tomorrow')],
            self::YESTERDAY => [new \DateTimeImmutable('yesterday'), new \DateTimeImmutable('today')],
            self::LAST_WEEK => [
                ($to = new \DateTimeImmutable('last sunday'))->modify('-1 week'),
                $to,
            ],
            self::LAST_MONTH => [
                new \DateTimeImmutable(\date('Y-m-d', \strtotime('first day of last month'))),
                new \DateTimeImmutable(\date('Y-m-d', \strtotime('first day of this month'))),
            ],
            self::ALL => [null, null],
            self::OLDER_THAN_1_HOUR => [null, $now->modify('-1 hour')],
            self::OLDER_THAN_1_DAY => [null, $now->modify('-24 hours')],
            self::OLDER_THAN_1_WEEK => [null, $now->modify('-7 days')],
            self::OLDER_THAN_1_MONTH => [null, $now->modify('-1 month')],
        };
    }
}
