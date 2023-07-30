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
        $normalizer = new ResultNormalizer();

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
        $normalizer = new ResultNormalizer();

        $this->assertSame([], $normalizer->normalizeException(new \RuntimeException('foo')));
    }

    /**
     * @test
     */
    public function normalize_http_response(): void
    {
        $normalizer = new ResultNormalizer();
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
        $normalizer = new ResultNormalizer();
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())->method('getStatusCode')->willThrowException($this->createMock(ExceptionInterface::class));

        $this->assertSame([], $normalizer->normalize($response));
    }

    /**
     * @test
     */
    public function normalize_http_response_exception(): void
    {
        $normalizer = new ResultNormalizer();
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())->method('getStatusCode')->willReturn(200);
        $response->expects($this->once())->method('getHeaders')->willReturn(['header' => 'value']);
        $response->expects($this->once())->method('getInfo')->willReturn(['info' => 'value']);
        $exception = $this->createMock(HttpExceptionInterface::class);
        $exception->expects($this->once())->method('getResponse')->willReturn($response);

        $this->assertSame(
            [
                'status_code' => 200,
                'headers' => ['header' => 'value'],
                'info' => ['info' => 'value'],
            ],
            $normalizer->normalizeException($exception),
        );
    }

    /**
     * @test
     */
    public function normalize_process(): void
    {
        if (!\class_exists(RunProcessMessage::class)) {
            $this->markTestSkipped('symfony/process 6.4+ required.');
        }

        $normalizer = new ResultNormalizer();
        $context = (new RunProcessMessageHandler())(new RunProcessMessage(['ls']));

        $this->assertSame(
            [
                'exit_code' => $context->exitCode,
                'output' => $context->output,
                'error_output' => $context->errorOutput,
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

        $normalizer = new ResultNormalizer();

        try {
            (new RunProcessMessageHandler())(new RunProcessMessage(['invalid']));
        } catch (RunProcessFailedException $e) {
            $this->assertSame(
                [
                    'exit_code' => 127,
                    'output' => '',
                    'error_output' => "sh: 1: exec: invalid: not found\n",
                ],
                $normalizer->normalizeException($e)
            );

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

        $normalizer = new ResultNormalizer();
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

        $normalizer = new ResultNormalizer();
        $context = new RunCommandFailedException('fail', new RunCommandContext(new RunCommandMessage('command'), 1, 'output'));

        $this->assertSame(['exit_code' => 1, 'output' => 'output'], $normalizer->normalizeException($context));
    }
}
