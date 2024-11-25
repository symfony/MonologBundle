<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MonologBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\MonologBundle\DependencyInjection\Compiler\LoggerChannelPass;
use Symfony\Bundle\MonologBundle\DependencyInjection\MonologExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class LoggerChannelPassTest extends TestCase
{
    public function testProcess()
    {
        $container = $this->getContainer();
        $this->assertTrue($container->hasDefinition('monolog.logger.test'), '->process adds a logger service for tagged service');

        $service = $container->getDefinition('test');
        $this->assertEquals('monolog.logger.test', (string) $service->getArgument(1), '->process replaces the logger by the new one');

        // pushHandlers for service "test"
        $expected = [
            'test' => ['monolog.handler.a', 'monolog.handler.b', 'monolog.handler.c'],
            'foo' => ['monolog.handler.b'],
            'bar' => ['monolog.handler.b', 'monolog.handler.c'],
        ];

        foreach ($expected as $serviceName => $handlers) {
            $service = $container->getDefinition($serviceName);
            $channel = $container->getDefinition((string) $service->getArgument(1));

            $calls = $channel->getMethodCalls();
            $this->assertCount(\count($handlers), $calls);
            foreach ($handlers as $i => $handler) {
                [$methodName, $arguments] = $calls[$i];
                $this->assertEquals('pushHandler', $methodName);
                $this->assertCount(1, $arguments);
                $this->assertEquals($handler, (string) $arguments[0]);
            }
        }

        $this->assertNotNull($container->getDefinition('monolog.logger.additional'));
    }

    public function testTypeHintedAliasesExistForEachChannel()
    {
        $container = $this->getContainer();
        $expectedChannels = ['test', 'foo', 'bar', 'additional'];

        foreach ($expectedChannels as $channelName) {
            $aliasName = LoggerInterface::class.' $'.$channelName.'Logger';
            $this->assertTrue($container->hasAlias($aliasName), 'type-hinted alias should be exists for each logger channel');
        }
    }

    public function testProcessSetters()
    {
        $container = $this->getContainerWithSetter();
        $this->assertTrue($container->hasDefinition('monolog.logger.test'), '->process adds a logger service for tagged service');

        $service = $container->getDefinition('foo');
        $calls = $service->getMethodCalls();
        $this->assertEquals('monolog.logger.test', (string) $calls[0][1][0], '->process replaces the logger by the new one in setters');
    }

    public function testAutowiredLoggerArgumentsAreReplacedWithChannelLogger()
    {
        $container = $this->getFunctionalContainer();

        $dummyService = $container->register('dummy_service', 'Symfony\Bundle\MonologBundle\Tests\DependencyInjection\Compiler\DummyService')
            ->setAutowired(true)
            ->setPublic(true)
            ->addTag('monolog.logger', ['channel' => 'test']);

        $container->compile();

        $this->assertEquals('monolog.logger.test', (string) $dummyService->getArgument(0));
    }

    public function testAutowiredLoggerArgumentsAreReplacedWithChannelLoggerWhenAutoconfigured()
    {
        $container = $this->getFunctionalContainer();

        $container->registerForAutoconfiguration('Symfony\Bundle\MonologBundle\Tests\DependencyInjection\Compiler\DummyService')
            ->setProperty('fake', 'dummy');

        $container->register('dummy_service', 'Symfony\Bundle\MonologBundle\Tests\DependencyInjection\Compiler\DummyService')
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->addTag('monolog.logger', ['channel' => 'test']);

        $container->compile();

        $this->assertEquals('monolog.logger.test', (string) $container->getDefinition('dummy_service')->getArgument(0));
    }

    public function testAutowiredLoggerArgumentsAreNotReplacedWithChannelLoggerIfLoggerArgumentIsConfiguredExplicitly()
    {
        $container = $this->getFunctionalContainer();

        $dummyService = $container->register('dummy_service', 'Symfony\Bundle\MonologBundle\Tests\DependencyInjection\Compiler\DummyService')
            ->setAutowired(true)
            ->addArgument(new Reference('monolog.logger'))
            ->addTag('monolog.logger', ['channel' => 'test']);

        $container->compile();

        $this->assertEquals('monolog.logger', (string) $dummyService->getArgument(0));
    }

    public function testTagNotBreakingIfNoLogger()
    {
        $container = $this->getFunctionalContainer();

        $dummyService = $container->register('dummy_service', 'stdClass')
            ->addTag('monolog.logger', ['channel' => 'test']);

        $container->compile();

        $this->assertEquals([], $dummyService->getArguments());
    }

    public function testChannelsConfigurationOptionSupportsAppChannel()
    {
        $container = $this->getFunctionalContainer();

        $container->setParameter('monolog.additional_channels', ['app']);
        $container->compile();

        // the test ensures that the validation does not fail (i.e. it does not throw any exceptions)
        $this->addToAssertionCount(1);
    }

    private function getContainer()
    {
        $container = new ContainerBuilder();
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../../../Resources/config'));
        $loader->load('monolog.xml');
        $definition = $container->getDefinition('monolog.logger_prototype');
        $container->set('monolog.handler.test', new Definition('%monolog.handler.null.class%', [100, false]));
        $definition->addMethodCall('pushHandler', [new Reference('monolog.handler.test')]);

        // Handlers
        $container->set('monolog.handler.a', new Definition('%monolog.handler.null.class%', [100, false]));
        $container->set('monolog.handler.b', new Definition('%monolog.handler.null.class%', [100, false]));
        $container->set('monolog.handler.c', new Definition('%monolog.handler.null.class%', [100, false]));

        // Channels
        foreach (['test', 'foo', 'bar'] as $name) {
            $service = new Definition('TestClass', ['false', new Reference('logger')]);
            $service->addTag('monolog.logger', ['channel' => $name]);
            $container->setDefinition($name, $service);
        }

        $container->setParameter('monolog.additional_channels', ['additional']);
        $container->setParameter('monolog.handlers_to_channels', [
            'monolog.handler.a' => [
                'type' => 'inclusive',
                'elements' => ['test'],
            ],
            'monolog.handler.b' => null,
            'monolog.handler.c' => [
                'type' => 'exclusive',
                'elements' => ['foo'],
            ],
        ]);

        $container->getCompilerPassConfig()->setOptimizationPasses([]);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->addCompilerPass(new LoggerChannelPass());
        $container->compile();

        return $container;
    }

    private function getContainerWithSetter()
    {
        $container = new ContainerBuilder();
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../../../Resources/config'));
        $loader->load('monolog.xml');
        $definition = $container->getDefinition('monolog.logger_prototype');
        $container->set('monolog.handler.test', new Definition('%monolog.handler.null.class%', [100, false]));
        $definition->addMethodCall('pushHandler', [new Reference('monolog.handler.test')]);

        // Channels
        $service = new Definition('TestClass');
        $service->addTag('monolog.logger', ['channel' => 'test']);
        $service->addMethodCall('setLogger', [new Reference('logger')]);
        $container->setDefinition('foo', $service);

        $container->setParameter('monolog.additional_channels', ['additional']);
        $container->setParameter('monolog.handlers_to_channels', []);

        $container->getCompilerPassConfig()->setOptimizationPasses([]);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->addCompilerPass(new LoggerChannelPass());
        $container->compile();

        return $container;
    }

    /**
     * @return ContainerBuilder
     */
    private function getFunctionalContainer()
    {
        $container = new ContainerBuilder();
        $container->setParameter('monolog.additional_channels', []);
        $container->setParameter('monolog.handlers_to_channels', []);
        $container->setParameter('monolog.use_microseconds', true);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../../../Resources/config'));
        $loader->load('monolog.xml');

        $container->addCompilerPass(new LoggerChannelPass());

        // disable removing passes to be able to inspect the container before all the inlining optimizations
        $container->getCompilerPassConfig()->setRemovingPasses([]);

        return $container;
    }

    public function testEnabledHandler()
    {
        $container = new ContainerBuilder();
        $loader = new MonologExtension();

        $config = [
            'handlers' => [
                'main' => [
                    'enabled' => true,
                    'type' => 'stream',
                    'path' => '%kernel.logs_dir%/%kernel.environment%.log',
                    'level' => 'debug',
                ],
            ],
        ];

        $loader->load([$config], $container);

        $this->assertTrue($container->hasDefinition('monolog.handler.main'));
    }

    public function testDisabledHandler()
    {
        $container = new ContainerBuilder();
        $loader = new MonologExtension();

        $config = [
            'handlers' => [
                'main' => [
                    'enabled' => false,
                    'type' => 'stream',
                    'path' => '%kernel.logs_dir%/%kernel.environment%.log',
                    'level' => 'debug',
                ],
            ],
        ];

        $loader->load([$config], $container);

        $this->assertFalse($container->hasDefinition('monolog.handler.main'));
    }
}

class DummyService
{
    public function __construct(LoggerInterface $logger)
    {
    }
}
