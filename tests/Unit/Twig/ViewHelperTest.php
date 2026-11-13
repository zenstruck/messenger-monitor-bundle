<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\SameOriginCsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Zenstruck\Messenger\Monitor\Transports;
use Zenstruck\Messenger\Monitor\Twig\ViewHelper;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Workers;

final class ViewHelperTest extends TestCase
{
    /**
     * @test
     * @dataProvider memoryChanges
     */
    public function formats_signed_memory_changes(int $bytes, string $expected): void
    {
        $this->assertSame($expected, $this->helper(null)->formatMemoryChange($bytes));
    }

    /** @return iterable<array{int, string}> */
    public static function memoryChanges(): iterable
    {
        yield [0, '0 B'];
        yield [2000, '+2 kB'];
        yield [-2000, '-2 kB'];
        yield [2097152, '+2.10 MB'];
        yield [-2097152, '-2.10 MB'];
    }

    /** @test */
    public function token_generation_remains_optional_for_read_only_views(): void
    {
        $helper = $this->helper(null);

        $this->assertSame('', $helper->generateCsrfToken('remove'));
    }

    /**
     * @test
     * @dataProvider unprotectedTokens
     */
    public function validation_requires_csrf_protection(string $token): void
    {
        $helper = $this->helper(null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CSRF protection is not enabled');

        $helper->validateCsrfToken($token, 'remove');
    }

    /** @return iterable<string, array{string}> */
    public static function unprotectedTokens(): iterable
    {
        yield 'empty token' => [''];
        yield 'forged token' => ['forged'];
    }

    /** @test */
    public function generated_tokens_remain_valid_for_each_action(): void
    {
        $helper = $this->helper($this->tokenManager());

        foreach (['remove', 'retry', 'trigger'] as $action) {
            $token = $helper->generateCsrfToken($action);
            $this->assertNotSame('', $token);

            $helper->validateCsrfToken($token, $action);
        }
    }

    /**
     * @test
     * @dataProvider invalidTokens
     */
    public function rejects_tokens_outside_their_context(string $context): void
    {
        $helper = $this->helper($this->tokenManager());
        $helper->generateCsrfToken('remove');
        $token = match ($context) {
            'empty' => '',
            'forged' => 'forged',
            'action' => $helper->generateCsrfToken('retry'),
            'session' => $this->helper($this->tokenManager())->generateCsrfToken('remove'),
        };

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid CSRF token.');

        $helper->validateCsrfToken($token, 'remove');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokens(): iterable
    {
        foreach (['empty', 'forged', 'action', 'session'] as $context) {
            yield $context => [$context];
        }
    }

    /** @test */
    public function actions_can_be_stateless_while_host_forms_remain_stateful(): void
    {
        if (!\class_exists(SameOriginCsrfTokenManager::class)) {
            $this->markTestSkipped('Stateless CSRF requires Symfony 7.2+.');
        }

        $request = Request::create('https://example.com/monitor', 'POST', server: ['HTTP_ORIGIN' => 'https://example.com']);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $requests = new RequestStack();
        $requests->push($request);
        $manager = new SameOriginCsrfTokenManager($requests, null, new CsrfTokenManager(null, new SessionTokenStorage($requests)), [
            'zenstruck_messenger_monitor_remove',
            'zenstruck_messenger_monitor_retry',
            'zenstruck_messenger_monitor_trigger',
        ]);
        $helper = $this->helper($manager);

        foreach (['remove', 'retry', 'trigger'] as $action) {
            $token = $helper->generateCsrfToken($action);
            $this->assertSame('csrf-token', $token);
            $helper->validateCsrfToken($token, $action);
        }

        $this->assertFalse($session->isStarted());
        $hostToken = $manager->getToken('host-form');
        $this->assertNotSame('csrf-token', $hostToken->getValue());
        $this->assertTrue($session->isStarted());
        $this->assertTrue($manager->isTokenValid($hostToken));
    }

    /**
     * @test
     * @dataProvider statelessRequests
     */
    public function stateless_validation_uses_symfonys_origin_and_double_submit_checks(string $context, bool $valid): void
    {
        if (!\class_exists(SameOriginCsrfTokenManager::class)) {
            $this->markTestSkipped('Stateless CSRF requires Symfony 7.2+.');
        }

        $request = Request::create('https://example.com/monitor', 'POST');
        $cookieName = 'custom-cookie' === $context ? 'monitor-csrf' : 'csrf-token';
        $token = $cookieName;

        if (\in_array($context, ['origin', 'remembered double-submit without cookie', 'remembered double-submit with cookie', 'empty', 'forged'], true)) {
            $request->headers->set('Origin', 'https://example.com');
        }

        if ('referer' === $context) {
            $request->headers->set('Referer', 'https://example.com/monitor');
        }

        if (\str_starts_with($context, 'remembered double-submit')) {
            $session = new Session(new MockArraySessionStorage());
            $session->set($cookieName, 3);
            $request->setSession($session);
            $request->cookies->set($session->getName(), $session->getId());
        }

        if (\in_array($context, ['double-submit', 'custom-cookie', 'cross-origin with cookie', 'remembered double-submit with cookie'], true)) {
            $token = \base64_encode(\random_bytes(18));
            $request->cookies->set('__Host-'.$cookieName.'_'.$token, $cookieName);
        }

        if (\str_starts_with($context, 'cross-origin')) {
            $request->headers->set('Origin', 'https://example.com.attacker.test');
        }

        $token = match ($context) {
            'empty' => '',
            'forged' => 'forged',
            default => $token,
        };
        $requests = new RequestStack();
        $requests->push($request);
        $helper = $this->helper(new SameOriginCsrfTokenManager($requests, cookieName: $cookieName));

        if (!$valid) {
            $this->expectException(HttpException::class);
            $this->expectExceptionMessage('Invalid CSRF token.');
        }

        $helper->validateCsrfToken($token, 'remove');
        $this->assertSame($cookieName, $helper->generateCsrfToken('remove'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function statelessRequests(): iterable
    {
        foreach (['origin', 'referer', 'double-submit', 'custom-cookie', 'remembered double-submit with cookie'] as $context) {
            yield $context => [$context, true];
        }

        foreach (['cross-origin', 'cross-origin with cookie', 'no origin', 'remembered double-submit without cookie', 'empty', 'forged'] as $context) {
            yield $context => [$context, false];
        }
    }

    private function helper(?CsrfTokenManagerInterface $tokenManager): ViewHelper
    {
        $workers = new Workers(new WorkerCache(new NullAdapter()));
        $transports = new Transports(new ServiceLocator([]), $workers);

        return new ViewHelper($transports, $workers, null, null, null, $tokenManager);
    }

    private function tokenManager(): CsrfTokenManager
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requests = new RequestStack();
        $requests->push($request);

        return new CsrfTokenManager(null, new SessionTokenStorage($requests));
    }
}
