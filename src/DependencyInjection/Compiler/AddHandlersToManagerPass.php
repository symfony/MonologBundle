<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MonologBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;

/**
 * Injects all handler services into the handler manager as an iterator of weak
 * references, so that only handlers already instantiated are closed on kernel
 * shutdown.
 *
 * @author Andy Clyde <andyclyde@educake.co.uk>
 *
 * @internal
 */
class AddHandlersToManagerPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public function process(ContainerBuilder $container): void
    {
        $taggedIteratorArgument = new TaggedIteratorArgument('monolog.handler');
        $references = $this->findAndSortTaggedServices($taggedIteratorArgument, $container);

        // Weak references skip handlers that have not been instantiated yet, so unused
        // handlers are not instantiated (and do not open resources) just to be closed.
        foreach ($references as $key => $reference) {
            $references[$key] = new Reference((string) $reference, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
        }

        $container->getDefinition('monolog.handler_lifecycle_manager')
            ->setArgument(0, new IteratorArgument($references));

        // Container shutdown callbacks are available since Symfony 8.2.
        if (!is_a(ServicesResetterInterface::class, 'Symfony\\Contracts\\Service\\ShutdownInterface', true)) {
            return;
        }

        foreach ($references as $reference) {
            $container->findDefinition((string) $reference)
                ->addTag('kernel.reset', ['method' => 'close', 'on_shutdown' => 'only']);
        }
    }
}
