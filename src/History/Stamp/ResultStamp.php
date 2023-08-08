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
use Zenstruck\Messenger\Monitor\History\Model\Result;
use Zenstruck\Messenger\Monitor\History\Model\Results;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 *
 * @phpstan-import-type Structure from Result
 */
final class ResultStamp implements NonSendableStampInterface
{
    /** @var Structure[] */
    private array $value;

    /**
     * @param Structure[] $result
     */
    public function __construct(array $result)
    {
        \array_walk_recursive($result, static function(mixed &$value) {
            $value = self::normalize($value);
        });

        $this->value = $result;
    }

    public function results(): Results
    {
        return new Results($this->value);
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
