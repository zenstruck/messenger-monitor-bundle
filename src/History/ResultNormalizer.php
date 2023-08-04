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

use Symfony\Component\Console\Exception\RunCommandFailedException;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Process\Exception\RunProcessFailedException;
use Symfony\Component\Process\Messenger\RunProcessContext;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Collection\ArrayCollection;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class ResultNormalizer
{
    /**
     * @return array<string,mixed>
     */
    public function normalize(mixed $result): array
    {
        if (null === $result) {
            return [];
        }

        if ($result instanceof RunProcessContext) { // @phpstan-ignore-line
            return [
                'exit_code' => $result->exitCode, // @phpstan-ignore-line
                'output' => self::trim($result->output), // @phpstan-ignore-line
                'error_output' => self::trim($result->errorOutput), // @phpstan-ignore-line
            ];
        }

        if ($result instanceof RunCommandContext) { // @phpstan-ignore-line
            return [
                'exit_code' => $result->exitCode, // @phpstan-ignore-line
                'output' => self::trim($result->output), // @phpstan-ignore-line
            ];
        }

        if ($result instanceof ResponseInterface) {
            return self::normalizeResponse($result);
        }

        if (\is_object($result)) {
            return \array_filter([
                'class' => $result::class,
                'data' => $result instanceof \Stringable ? (string) $result : null,
            ]);
        }

        return \is_array($result) ? $result : ['data' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeException(\Throwable $exception): array
    {
        if ($exception instanceof RunProcessFailedException) { // @phpstan-ignore-line
            return $this->normalize($exception->context); // @phpstan-ignore-line
        }

        if ($exception instanceof RunCommandFailedException) { // @phpstan-ignore-line
            return $this->normalize($exception->context); // @phpstan-ignore-line
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $this->normalize($exception->getResponse());
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private static function normalizeResponse(ResponseInterface $response): array
    {
        try {
            return [
                'status_code' => $response->getStatusCode(),
                'headers' => $response->getHeaders(throw: false),
                'info' => $response->getInfo(),
            ];
        } catch (HttpClientException) {
            return [];
        }
    }

    private static function trim(?string $output): ?string
    {
        if (null === $output) {
            return null;
        }

        return \trim(
            ArrayCollection::explode("\n", $output)
                ->map(fn(string $line) => \rtrim($line, ' '))
                ->implode("\n")
        );
    }
}
