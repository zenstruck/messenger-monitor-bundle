<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\History;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\RunCommandFailedException;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Process\Exception\RunProcessFailedException;
use Symfony\Component\Process\Messenger\RunProcessMessage;
use Symfony\Component\Process\Messenger\RunProcessMessageHandler;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Messenger\Monitor\History\Model\Tags;
use Zenstruck\Messenger\Monitor\History\ResultNormalizer;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class ResultNormalizerTest extends TestCase
{
    /**
     * @test
     */
    public function normalize(): void
    {
        $normalizer = new ResultNormalizer(__DIR__);

        $this->assertSame([], $normalizer->normalize(null));
        $this->assertSame(['data' => 'foo'], $normalizer->normalize('foo'));
        $this->assertSame(['data' => 1], $normalizer->normalize(1));
        $this->assertSame(['foo' => 'bar'], $normalizer->normalize(['foo' => 'bar']));
        $this->assertSame(['class' => 'stdClass'], $normalizer->normalize(new \stdClass()));
        $this->assertSame(['class' => Tags::class, 'data' => 'foo,bar'], $normalizer->normalize(new Tags('foo, bar')));
    }

    /**
     * @test
     */
    public function normalize_exception(): void
    {
        $result = (new ResultNormalizer(__DIR__))->normalize(new \RuntimeException('foo'));

        $this->assertStringContainsString(__FUNCTION__, $result['stack_trace']);
    }

    /**
     * @test
     */
    public function normalize_http_response(): void
    {
        $normalizer = new ResultNormalizer(__DIR__);
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())->method('getStatusCode')->willReturn(200);
        $response->expects($this->once())->method('getHeaders')->willReturn(['header' => 'value']);
        $response->expects($this->once())->method('getInfo')->willReturn(['info' => 'value']);

        $this->assertSame(
            [
                'status_code' => 200,
                'headers' => ['header' => 'value'],
                'info' => ['info' => 'value'],
            ],
            $normalizer->normalize($response),
        );
    }

    /**
     * @test
     */
    public function normalize_http_response_fails(): void
    {
        $normalizer = new ResultNormalizer(__DIR__);
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())->method('getStatusCode')->willThrowException($this->createMock(ExceptionInterface::class));

        $this->assertSame([], $normalizer->normalize($response));
    }

    /**
     * @test
     */
    public function normalize_http_response_exception(): void
    {
        $normalizer = new ResultNormalizer(__DIR__);
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())->method('getStatusCode')->willReturn(200);
        $response->expects($this->once())->method('getHeaders')->willReturn(['header' => 'value']);
        $response->expects($this->once())->method('getInfo')->willReturn(['info' => 'value']);
        $exception = $this->createMock(HttpExceptionInterface::class);
        $exception->expects($this->once())->method('getResponse')->willReturn($response);

        $result = $normalizer->normalize($exception);

        $this->assertStringContainsString(__FUNCTION__, $result['stack_trace']);
        $this->assertSame(200, $result['status_code']);
        $this->assertSame(['header' => 'value'], $result['headers']);
        $this->assertSame(['info' => 'value'], $result['info']);
    }

    /**
     * @test
     */
    public function normalize_process(): void
    {
        if (!\class_exists(RunProcessMessage::class)) {
            $this->markTestSkipped('symfony/process 6.4+ required.');
        }

        $normalizer = new ResultNormalizer(__DIR__);
        $context = (new RunProcessMessageHandler())(new RunProcessMessage(['ls']));

        $this->assertSame(
            [
                'exit_code' => $context->exitCode,
                'output' => \trim($context->output),
                'error_output' => \trim($context->errorOutput),
            ],
            $normalizer->normalize($context),
        );
    }

    /**
     * @test
     */
    public function normalize_process_exception(): void
    {
        if (!\class_exists(RunProcessMessage::class)) {
            $this->markTestSkipped('symfony/process 6.4+ required.');
        }

        $normalizer = new ResultNormalizer(__DIR__);

        try {
            (new RunProcessMessageHandler())(new RunProcessMessage([
                (new PhpExecutableFinder())->find(), '-r', "file_put_contents('php://stdout', 'STANDARD') && file_put_contents('php://stderr', 'ERROR') && exit(127);",
            ]));
        } catch (RunProcessFailedException $e) {
            $result = $normalizer->normalize($e);

            $this->assertStringContainsString(__FUNCTION__, $result['stack_trace']);
            $this->assertSame(127, $result['exit_code']);
            $this->assertSame('STANDARD', $result['output']);
            $this->assertSame('ERROR', $result['error_output']);

            return;
        }

        $this->fail('Exception not thrown.');
    }

    /**
     * @test
     */
    public function normalize_run_command_context(): void
    {
        if (!\class_exists(RunCommandContext::class)) {
            $this->markTestSkipped('symfony/console 6.4+ required.');
        }

        $normalizer = new ResultNormalizer(__DIR__);
        $context = new RunCommandContext(new RunCommandMessage('command'), 0, 'output');

        $this->assertSame(['exit_code' => 0, 'output' => 'output'], $normalizer->normalize($context));
    }

    /**
     * @test
     */
    public function normalize_run_command_exception(): void
    {
        if (!\class_exists(RunCommandContext::class)) {
            $this->markTestSkipped('symfony/console 6.4+ required.');
        }

        $normalizer = new ResultNormalizer(__DIR__);
        $context = new RunCommandFailedException('fail', new RunCommandContext(new RunCommandMessage('command'), 1, 'output'));
        $result = $normalizer->normalize($context);

        $this->assertSame(1, $result['exit_code']);
        $this->assertSame('output', $result['output']);
        $this->assertStringContainsString(__FUNCTION__, $result['stack_trace']);
    }

    /**
     * @test
     */
    public function converts_values_to_scalar(): void
    {
        $normalizer = new ResultNormalizer(__DIR__);

        $this->assertSame($this->normalizedValues(), $normalizer->normalize($this->rawValues()));
    }

    private function rawValues(): array
    {
        try {
            return [
                'nested1' => [
                    'nested2' => [
                        'datetime' => new \DateTime('2021-01-04 11:22:13 America/New_York'),
                        'int' => 56,
                        'array' => ['value1', 'value2', fn($a) => $a],
                        'resource' => $resource = \fopen(__FILE__, 'r'),
                        'nested3' => [
                            'foo' => 'bar',
                        ],
                    ],
                    'float' => 65.6,
                    'function' => fn($a) => $a,
                    'object1' => new \stdClass(),
                ],
                'string' => 'value',
                'null' => null,
            ];
        } finally {
            \fclose($resource);
        }
    }

    private function normalizedValues(): array
    {
        return [
            'nested1' => [
                'nested2' => [
                    'datetime' => '2021-01-04T11:22:13-05:00',
                    'int' => 56,
                    'array' => ['value1', 'value2', 'Closure'],
                    'resource' => 'resource (closed)',
                    'nested3' => [
                        'foo' => 'bar',
                    ],
                ],
                'float' => 65.6,
                'function' => 'Closure',
                'object1' => \stdClass::class,
            ],
            'string' => 'value',
            'null' => null,
        ];
    }
}
