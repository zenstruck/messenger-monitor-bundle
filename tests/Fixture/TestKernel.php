<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Fixture;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Zenstruck\Messenger\Monitor\ZenstruckMessengerMonitorBundle;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ZenstruckMessengerMonitorBundle();
    }

    protected function configureContainer(ContainerBuilder $c, LoaderInterface $loader): void
    {
        $c->loadFromExtension('framework', [
            'http_method_override' => false,
            'secret' => 'S3CRET',
            'router' => ['utf8' => true],
            'test' => true,
            'messenger' => [
                'transports' => [
                    'async' => 'in-memory://',
                ],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}
