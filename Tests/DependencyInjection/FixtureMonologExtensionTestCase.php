<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MonologBundle\Tests\DependencyInjection;

use Monolog\Handler\FingersCrossed\ErrorLevelActivationStrategy;
use Symfony\Bundle\MonologBundle\DependencyInjection\Compiler\LoggerChannelPass;
use Symfony\Bundle\MonologBundle\DependencyInjection\MonologExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

abstract class FixtureMonologExtensionTestCase extends DependencyInjectionTestCase
{
    public function testLoadWithSeveralHandlers()
    {
        $activation = new Definition(ErrorLevelActivationStrategy::class, ['ERROR']);
        $container = $this->getContainer('multiple_handlers');

        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.custom'));
        $this->assertTrue($container->hasDefinition('monolog.handler.main'));
        $this->assertTrue($container->hasDefinition('monolog.handler.nested'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertCount(4, $logger->getMethodCalls());
        $this->assertDICDefinitionMethodCallAt(3, $logger, 'pushHandler', [new Reference('monolog.handler.custom')]);
        $this->assertDICDefinitionMethodCallAt(2, $logger, 'pushHandler', [new Reference('monolog.handler.main')]);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.filtered')]);
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);

        $handler = $container->getDefinition('monolog.handler.custom');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\StreamHandler');
        $this->assertDICConstructorArguments($handler, ['/tmp/symfony.log', 'ERROR', false, 0666, false]);

        $handler = $container->getDefinition('monolog.handler.main');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\FingersCrossedHandler');
        $this->assertDICConstructorArguments($handler, [new Reference('monolog.handler.nested'), $activation, 0, true, true, 'NOTICE']);

        $handler = $container->getDefinition('monolog.handler.filtered');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\FilterHandler');
        $this->assertDICConstructorArguments($handler, [new Reference('monolog.handler.nested2'), ['WARNING', 'ERROR'], 'EMERGENCY', true]);
    }

    public function testLoadWithOverwriting()
    {
        $activation = new Definition(ErrorLevelActivationStrategy::class, ['ERROR']);
        $container = $this->getContainer('overwriting');

        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.custom'));
        $this->assertTrue($container->hasDefinition('monolog.handler.main'));
        $this->assertTrue($container->hasDefinition('monolog.handler.nested'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertCount(3, $logger->getMethodCalls());
        $this->assertDICDefinitionMethodCallAt(2, $logger, 'pushHandler', [new Reference('monolog.handler.custom')]);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.main')]);
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);

        $handler = $container->getDefinition('monolog.handler.custom');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\StreamHandler');
        $this->assertDICConstructorArguments($handler, ['/tmp/symfony.log', 'WARNING', true, null, false]);

        $handler = $container->getDefinition('monolog.handler.main');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\FingersCrossedHandler');
        $this->assertDICConstructorArguments($handler, [new Reference('monolog.handler.nested'), $activation, 0, true, true, null]);
    }

    public function testLoadWithNewAtEnd()
    {
        $container = $this->getContainer('new_at_end');

        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.custom'));
        $this->assertTrue($container->hasDefinition('monolog.handler.main'));
        $this->assertTrue($container->hasDefinition('monolog.handler.nested'));
        $this->assertTrue($container->hasDefinition('monolog.handler.new'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertCount(4, $logger->getMethodCalls());
        $this->assertDICDefinitionMethodCallAt(3, $logger, 'pushHandler', [new Reference('monolog.handler.custom')]);
        $this->assertDICDefinitionMethodCallAt(2, $logger, 'pushHandler', [new Reference('monolog.handler.main')]);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.new')]);
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);

        $handler = $container->getDefinition('monolog.handler.new');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\StreamHandler');
        $this->assertDICConstructorArguments($handler, ['/tmp/monolog.log', 'ERROR', true, null, false]);
    }

    public function testLoadWithNewAndPriority()
    {
        $container = $this->getContainer('new_and_priority');

        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.custom'));
        $this->assertTrue($container->hasDefinition('monolog.handler.main'));
        $this->assertTrue($container->hasDefinition('monolog.handler.nested'));
        $this->assertTrue($container->hasDefinition('monolog.handler.first'));
        $this->assertTrue($container->hasDefinition('monolog.handler.last'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertCount(5, $logger->getMethodCalls());
        $this->assertDICDefinitionMethodCallAt(4, $logger, 'pushHandler', [new Reference('monolog.handler.first')]);
        $this->assertDICDefinitionMethodCallAt(3, $logger, 'pushHandler', [new Reference('monolog.handler.custom')]);
        $this->assertDICDefinitionMethodCallAt(2, $logger, 'pushHandler', [new Reference('monolog.handler.main')]);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.last')]);
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);

        $handler = $container->getDefinition('monolog.handler.main');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\BufferHandler');
        $this->assertDICConstructorArguments($handler, [new Reference('monolog.handler.nested'), 0, 'INFO', true, false]);

        $handler = $container->getDefinition('monolog.handler.first');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\RotatingFileHandler');
        $this->assertDICConstructorArguments($handler, ['/tmp/monolog.log', 0, 'ERROR', true, null, false]);

        $handler = $container->getDefinition('monolog.handler.last');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\StreamHandler');
        $this->assertDICConstructorArguments($handler, ['/tmp/last.log', 'ERROR', true, null, false]);
    }

    public function testHandlersWithChannels()
    {
        $container = $this->getContainer('handlers_with_channels');

        $this->assertEquals(
            [
                'monolog.handler.custom' => ['type' => 'inclusive', 'elements' => ['foo']],
                'monolog.handler.main' => ['type' => 'exclusive', 'elements' => ['foo', 'bar']],
                'monolog.handler.extra' => null,
                'monolog.handler.more' => ['type' => 'inclusive', 'elements' => ['security', 'doctrine']],
            ],
            $container->getParameter('monolog.handlers_to_channels')
        );
    }

    /** @group legacy */
    public function testSingleEmailRecipient()
    {
        if (\Monolog\Logger::API >= 3) {
            $this->markTestSkipped('This test requires Monolog v2');
        }

        $container = $this->getContainer('single_email_recipient');

        $this->assertEquals([
            new Reference('mailer'),
            'error@example.com', // from
            ['error@example.com'], // to
            'An Error Occurred!', // subject
            null,
        ], $container->getDefinition('monolog.handler.swift.mail_message_factory')->getArguments());
    }

    public function testServerLog()
    {
        $container = $this->getContainer('server_log');

        $this->assertEquals([
            '0:9911',
            'DEBUG',
            true,
        ], $container->getDefinition('monolog.handler.server_log')->getArguments());
    }

    public function testMultipleEmailRecipients()
    {
        if (\Monolog\Logger::API >= 3) {
            $this->markTestSkipped('This test requires Monolog v2');
        }

        $container = $this->getContainer('multiple_email_recipients');

        $this->assertEquals([
            new Reference('mailer'),
            'error@example.com',
            ['dev1@example.com', 'dev2@example.com'],
            'An Error Occurred!',
            null,
        ], $container->getDefinition('monolog.handler.swift.mail_message_factory')->getArguments());
    }

    public function testChannelParametersResolved()
    {
        $container = $this->getContainer('parameterized_handlers');

        $this->assertEquals(
            [
                'monolog.handler.custom' => ['type' => 'inclusive', 'elements' => ['some_channel']],
            ],
            $container->getParameter('monolog.handlers_to_channels')
        );
    }

    public function testPsr3MessageProcessingEnabled()
    {
        $container = $this->getContainer('parameterized_handlers');

        $logger = $container->getDefinition('monolog.handler.custom');

        $methodCalls = $logger->getMethodCalls();

        $this->assertContainsEquals(['pushProcessor', [new Reference('monolog.processor.psr_log_message')]], $methodCalls, 'The PSR-3 processor should be enabled');
    }

    public function testPsr3MessageProcessingDisabledOnNullHandler()
    {
        $container = $this->getContainer('process_psr_3_messages_null');

        $logger = $container->getDefinition('monolog.handler.custom');

        $methodCalls = $logger->getMethodCalls();

        $this->assertNotContainsEquals(['pushProcessor', [new Reference('monolog.processor.psr_log_message')]], $methodCalls, 'The PSR-3 processor should not be enabled');
    }

    public function testHandlersV2()
    {
        $this->getContainer('handlers');

        $this->expectNotToPerformAssertions();
    }

    public function testPsr3MessageProcessingDisabled()
    {
        $container = $this->getContainer('process_psr_3_messages_disabled');

        $logger = $container->getDefinition('monolog.handler.custom');

        $methodCalls = $logger->getMethodCalls();

        $this->assertNotContainsEquals(['pushProcessor', [new Reference('monolog.processor.psr_log_message')]], $methodCalls, 'The PSR-3 processor should not be enabled');
    }

    public function testPsrLogMessageProcessorHasConstructorArguments(): void
    {
        $container = $this->getContainer('process_psr_3_messages_with_arguments');

        $processors = [
            'monolog.processor.psr_log_message' => ['name' => 'without_arguments', 'arguments' => []],
            'monolog.processor.psr_log_message.'.ContainerBuilder::hash($arguments = ['Y', false]) => [
                'name' => 'with_arguments',
                'arguments' => $arguments,
            ],
        ];
        foreach ($processors as $processorId => $settings) {
            $this->assertTrue($container->hasDefinition($processorId));
            $processor = $container->getDefinition($processorId);
            $this->assertDICConstructorArguments($processor, $settings['arguments']);

            $this->assertTrue($container->hasDefinition($handlerId = 'monolog.handler.'.$settings['name']));
            $handler = $container->getDefinition($handlerId);
            $this->assertDICDefinitionMethodCallAt(0, $handler, 'pushProcessor', [new Reference($processorId)]);
        }
    }

    public function testNativeMailer()
    {
        $container = $this->getContainer('native_mailer');

        $logger = $container->getDefinition('monolog.handler.mailer');
        $methodCalls = $logger->getMethodCalls();

        $this->assertCount(2, $methodCalls);
        $this->assertSame(['addHeader', [['Foo: bar', 'Baz: inga']]], $methodCalls[1]);
    }

    protected function getContainer($fixture)
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new MonologExtension());

        $this->loadFixture($container, $fixture);

        $container->getCompilerPassConfig()->setOptimizationPasses([]);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->addCompilerPass(new LoggerChannelPass());
        $container->compile();

        return $container;
    }

    abstract protected function loadFixture(ContainerBuilder $container, $fixture);
}
