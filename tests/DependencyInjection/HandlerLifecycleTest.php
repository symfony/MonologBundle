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

use Monolog\Handler\StreamHandler;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MonologBundle\DependencyInjection\MonologExtension;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\MonologBundle\Tests\DependencyInjection\Fixtures\LifecycleHandler;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Compiler\ResettableServicePass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\ServicesResetter;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\DependencyInjection\ResettableServicePass as HttpKernelResettableServicePass;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter as HttpKernelServicesResetter;

class HandlerLifecycleTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testShutdownFlushesBuffersAndClosesStreams(bool $nestedFirst)
    {
        $path = sys_get_temp_dir().'/monolog-shutdown-'.bin2hex(random_bytes(6)).'.log';
        $handlers = [
            'buffer' => ['type' => 'buffer', 'handler' => 'nested'],
            'nested' => ['type' => 'stream', 'path' => $path],
        ];
        $container = new ContainerBuilder();
        $bundle = new MonologBundle();
        $bundle->build($container);
        (new MonologExtension())->load([['handlers' => $nestedFirst ? array_reverse($handlers, true) : $handlers]], $container);
        $container->getDefinition('monolog.logger')->setPublic(true);
        $container->getDefinition('monolog.handler.nested')->setPublic(true);
        $container->register('services_resetter', class_exists(ServicesResetter::class) ? ServicesResetter::class : HttpKernelServicesResetter::class)->setPublic(true);
        $container->addCompilerPass(class_exists(ResettableServicePass::class) ? new ResettableServicePass() : new HttpKernelResettableServicePass(), PassConfig::TYPE_BEFORE_REMOVING);
        $container->compile();

        $class = 'MonologBufferedShutdownContainer'.(int) $nestedFirst;
        eval('?>'.(new PhpDumper($container))->dump(['class' => $class]));
        $container = new $class();
        $container->get('monolog.logger')->warning('buffered message');
        $nested = $container->get('monolog.handler.nested');
        $this->assertInstanceOf(StreamHandler::class, $nested);
        $this->assertFileDoesNotExist($path);

        try {
            $bundle->setContainer($container);
            $bundle->shutdown();
            if (is_a(ServicesResetterInterface::class, 'Symfony\Contracts\Service\ShutdownInterface', true)) {
                $container->get('services_resetter')->reset(true);
            }

            $this->assertStringContainsString('buffered message', file_get_contents($path));
            $this->assertNull($nested->getStream());
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testHandlersAreResetBetweenRequestsAndClosedOnShutdown()
    {
        $container = new ContainerBuilder();
        $bundle = new MonologBundle();
        $bundle->build($container);
        (new MonologExtension())->load([['handlers' => [
            'main' => ['type' => 'stream', 'include_stacktraces' => true],
            'buffer' => ['type' => 'buffer', 'handler' => 'nested'],
            'nested' => ['type' => 'stream'],
            'unused' => ['type' => 'stream', 'nested' => true],
        ]]], $container);

        foreach (['main', 'nested', 'unused'] as $name) {
            $container->getDefinition('monolog.handler.'.$name)
                ->setClass(LifecycleHandler::class)
                ->setArguments([])
                ->setMethodCalls([])
                ->setPublic(true);
        }
        $container->getDefinition('monolog.logger')->setPublic(true);
        $container->register('services_resetter', class_exists(ServicesResetter::class) ? ServicesResetter::class : HttpKernelServicesResetter::class)->setPublic(true);
        $container->addCompilerPass(class_exists(ResettableServicePass::class) ? new ResettableServicePass() : new HttpKernelResettableServicePass(), PassConfig::TYPE_BEFORE_REMOVING);
        $container->compile();

        eval('?>'.(new PhpDumper($container))->dump(['class' => 'MonologHandlerLifecycleContainer']));
        $container = new \MonologHandlerLifecycleContainer();
        $container->get('monolog.logger');
        $main = $container->get('monolog.handler.main');
        $nested = $container->get('monolog.handler.nested');

        $container->get('services_resetter')->reset();

        $this->assertSame(1, $main->resets);
        $this->assertSame(1, $nested->resets);
        $this->assertSame(0, $main->closes);
        $this->assertSame(0, $nested->closes);
        $this->assertFalse($container->initialized('monolog.handler.unused'));

        $bundle->setContainer($container);
        $bundle->shutdown();

        if (is_a(ServicesResetterInterface::class, 'Symfony\Contracts\Service\ShutdownInterface', true)) {
            $this->assertSame(0, $main->closes);
            $this->assertSame(0, $nested->closes);
            $container->get('services_resetter')->reset(true);
        }

        $expectedResets = is_a(ServicesResetterInterface::class, 'Symfony\\Contracts\\Service\\ShutdownInterface', true) ? 2 : 1;
        $this->assertSame($expectedResets, $main->resets);
        $this->assertSame($expectedResets, $nested->resets);
        $this->assertSame(1, $main->closes);
        $this->assertGreaterThanOrEqual(1, $nested->closes);
        $record = new \Monolog\LogRecord(new \DateTimeImmutable(), 'app', \Monolog\Level::Error, 'failure', ['exception' => new \RuntimeException('failure')]);
        $this->assertStringContainsString('[stacktrace]', $main->getFormatter()->format($record));
        $this->assertFalse($container->initialized('monolog.handler.unused'));
    }
}
