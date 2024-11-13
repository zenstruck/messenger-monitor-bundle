<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\History\Model;

use Zenstruck\Messenger\Monitor\Type;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @phpstan-type Structure = array{
 *     handler?: string,
 *     exception?: class-string<\Throwable>,
 *     message?: string,
 *     data: array<string,mixed>,
 * }
 */
final class Result
{
    /**
     * @internal
     *
     * @param Structure $data
     */
    public function __construct(private array $data)
    {
    }

    public function isFailure(): bool
    {
        return isset($this->data['exception']);
    }

    public function handler(): ?Type
    {
        if (!isset($this->data['handler']) || !\is_string($this->data['handler'])) {
            return null;
        }

        $parts = \explode('::', $this->data['handler'], 2);

        if (1 === \count($parts) || '__invoke' === $parts[1]) {
            return new Type($parts[0]); // @phpstan-ignore-line
        }

        return new Type($parts[0], $parts[1]); // @phpstan-ignore-line
    }

    /**
     * @return Type<\Throwable>|null
     */
    public function failure(): ?Type
    {
        return isset($this->data['exception']) ? new Type($this->data['exception'], $this->data['message'] ?? null) : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function data(): array
    {
        return $this->data['data'] ?? [];
    }
}
