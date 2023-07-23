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

use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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

        if ($result instanceof Process) {
            return self::normalizeProcess($result);
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
        if ($exception instanceof ProcessFailedException) {
            return $this->normalize($exception->getProcess());
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

    /**
     * @return array<string,mixed>
     */
    private static function normalizeProcess(Process $process): array
    {
        try {
            return [
                'exit_code' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'error_output' => $process->getErrorOutput(),
                'duration' => ($endTime = $process->getLastOutputTime()) ? $endTime - $process->getStartTime() : null,
            ];
        } catch (ProcessException) {
            return [];
        }
    }
}
