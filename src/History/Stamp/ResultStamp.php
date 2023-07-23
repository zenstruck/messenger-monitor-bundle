<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\History\Stamp;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class ResultStamp implements NonSendableStampInterface
{
    /** @var array<string,mixed> */
    public readonly array $value;

    /**
     * @param array<string,mixed> $result
     */
    public function __construct(array $result)
    {
        \array_walk_recursive($result, static function(mixed &$value) {
            $value = self::normalize($value);
        });

        $this->value = $result;
    }

    private static function normalize(mixed $value): int|float|string|bool|null
    {
        if (null === $value || \is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        return \get_debug_type($value);
    }
}
