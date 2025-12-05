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

use Doctrine\Bundle\DoctrineBundle\Dbal\BlacklistSchemaAssetFilter;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\Mapping\LegacyReflectionFields;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Zenstruck\Foundry\ZenstruckFoundryBundle;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Entity\ProcessedMessage;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageA;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageAHandler;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageB;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageC;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageCHandler1;
use Zenstruck\Messenger\Monitor\Tests\Fixture\Message\MessageCHandler2;
use Zenstruck\Messenger\Monitor\ZenstruckMessengerMonitorBundle;
use Zenstruck\Messenger\Test\ZenstruckMessengerTestBundle;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new ZenstruckMessengerTestBundle();
        yield new ZenstruckFoundryBundle();
        yield new ZenstruckMessengerMonitorBundle();
    }

    protected function configureContainer(ContainerBuilder $c, LoaderInterface $loader): void
    {
        $c->loadFromExtension('framework', [
            'http_method_override' => false,
            'secret' => 'S3CRET',
            'router' => ['utf8' => true],
            'test' => true,
            'serializer' => true,
            'messenger' => [
                'transports' => [
                    'async' => 'test://',
                ],
                'routing' => [
                    MessageA::class => 'async',
                    MessageB::class => 'async',
                    MessageC::class => 'async',
                ],
            ],
        ]);

        $c->loadFromExtension('zenstruck_messenger_monitor', [
            'storage' => [
                'orm' => [
                    'entity_class' => ProcessedMessage::class,
                ],
            ],
        ]);

        $c->loadFromExtension('doctrine', [
            'dbal' => ['url' => '%env(resolve:DATABASE_URL)%'],
            'orm' => [
                #'auto_generate_proxy_classes' => true,
                'auto_mapping' => true,
                'mappings' => [
                    'Test' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => '%kernel.project_dir%/tests/Fixture/Entity',
                        'prefix' => __NAMESPACE__.'\Entity',
                        'alias' => 'Test',
                    ],
                ],
            ],
        ]);

        $doctrineBundleV3 = !class_exists(BlacklistSchemaAssetFilter::class);

        if (!$doctrineBundleV3) {
            $c->prependExtensionConfig('doctrine', [
                'orm' => [
                    'auto_generate_proxy_classes' => true,
                ],
            ]);

            if (class_exists(LegacyReflectionFields::class) && PHP_VERSION_ID >= 80400) {
                $c->prependExtensionConfig('doctrine', [
                'orm' => [
                    'enable_lazy_ghost_objects' => true,
                    'enable_native_lazy_objects' => true,
                ],
            ]);
            }
        }

        $c->register(TestService::class)->setAutowired(true)->setPublic(true);
        $c->register(MessageAHandler::class)->setAutowired(true)->setAutoconfigured(true);
        $c->register(MessageCHandler1::class)->setAutowired(true)->setAutoconfigured(true);
        $c->register(MessageCHandler2::class)->setAutowired(true)->setAutoconfigured(true);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}
