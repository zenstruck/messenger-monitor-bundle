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
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
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
        $normalizer = new ResultNormalizer();
        $process = Process::fromShellCommandline('ls');
        $process->run();

        $this->assertSame(
            [
                'exit_code' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'error_output' => $process->getErrorOutput(),
                'duration' => $process->getLastOutputTime() - $process->getStartTime(),
            ],
            $normalizer->normalize($process),
        );
    }

    /**
     * @test
     */
    public function normalize_process_fails(): void
    {
        $normalizer = new ResultNormalizer();
        $process = Process::fromShellCommandline('ls');

        $this->assertSame([], $normalizer->normalize($process));
    }

    /**
     * @test
     */
    public function normalize_process_exception(): void
    {
        $normalizer = new ResultNormalizer();
        $process = Process::fromShellCommandline('invalid');
        $process->run();

        $this->assertSame(
            [
                'exit_code' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'error_output' => $process->getErrorOutput(),
                'duration' => $process->getLastOutputTime() - $process->getStartTime(),
            ],
            $normalizer->normalizeException(new ProcessFailedException($process)),
        );
    }
}
