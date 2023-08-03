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
 */
final class Failure implements \Stringable
{
    /** @var class-string<\Throwable> */
    private string $class;
    private string $message;

    public function __construct(\Throwable|string $exception)
    {
        if ($exception instanceof \Throwable) {
            $this->class = $exception::class;
            $this->message = $exception->getMessage();

            return;
        }

        [$class, $message] = \explode(':', $exception, 2);

        $this->class = $class; // @phpstan-ignore-line
        $this->message = \trim($message);
    }

    public function __toString(): string
    {
        return \sprintf('%s: %s', $this->class, $this->message);
    }

    /**
     * @return \Zenstruck\Messenger\Monitor\Type<\Throwable>
     */
    public function type(): Type
    {
        return new Type($this->class);
    }

    /**
     * @return class-string<\Throwable>
     */
    public function class(): string
    {
        return $this->class;
    }

    public function message(): string
    {
        return $this->message;
    }
}
