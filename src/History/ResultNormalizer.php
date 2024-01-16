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
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Message;
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
    public function __construct(private string $projectDir)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function normalize(mixed $result): array
    {
        $result = $this->doNormalize($result);

        \array_walk_recursive($result, static function(mixed &$value) {
            $value = self::convert($value);
        });

        return $result;
    }

    private static function convert(mixed $value): int|float|string|bool|null
    {
        if (null === $value || \is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        return \get_debug_type($value);
    }

    /**
     * @return array<string,mixed>
     */
    private function doNormalize(mixed $result): array
    {
        if (null === $result) {
            return [];
        }

        if ($result instanceof \Throwable) {
            return $this->normalizeException($result);
        }

        if ($result instanceof RunProcessContext) {
            return [
                'exit_code' => $result->exitCode,
                'output' => self::trim($result->output),
                'error_output' => self::trim($result->errorOutput),
            ];
        }

        if ($result instanceof RunCommandContext) {
            return [
                'exit_code' => $result->exitCode,
                'output' => self::trim($result->output),
            ];
        }

        if ($result instanceof ResponseInterface) {
            return self::normalizeResponse($result);
        }

        if ($result instanceof SentMessage) {
            return self::normalizeEmail($result);
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
    private function normalizeException(\Throwable $exception): array
    {
        $result = ['stack_trace' => $this->normalizeTrace($exception)];

        if ($previous = $exception->getPrevious()) {
            $result['previous_exception'] = $previous::class;
            $result['previous_message'] = $previous->getMessage();
            $result['previous_stack_trace'] = $this->normalizeTrace($previous);
        }

        if ($exception instanceof RunProcessFailedException) {
            return [...$result, ...$this->normalize($exception->context)];
        }

        if ($exception instanceof RunCommandFailedException) {
            return [...$result, ...$this->normalize($exception->context)];
        }

        if ($exception instanceof HttpExceptionInterface) {
            return [...$result, ...$this->normalize($exception->getResponse())];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeEmail(SentMessage $message): array
    {
        $original = $message->getOriginalMessage();

        if (!$original instanceof Message) {
            return ['class' => $message];
        }

        $headers = $original->getHeaders()->all();
        $headers = $headers instanceof \Traversable ? \iterator_to_array($headers) : $headers;
        $headers = \array_map(static fn(HeaderInterface $header) => $header->getBodyAsString(), $headers);

        return [
            'id' => $message->getMessageId(),
            'headers' => $headers,
            'debug' => $message->getDebug(),
        ];
    }

    private function normalizeTrace(\Throwable $exception): string
    {
        return \str_replace($this->projectDir, '', $exception->getTraceAsString());
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
                ->implode("\n"),
        );
    }
}
