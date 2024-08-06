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

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers processors in Monolog loggers or handlers.
 *
 * @author Christophe Coevoet <stof@notk.org>
 *
 * @internal since 3.9.0
 */
class AddProcessorsPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public function process(ContainerBuilder $container)
    {
        if (!$container->hasDefinition('monolog.logger')) {
            return;
        }

        // array_reverse is used because ProcessableHandlerTrait::pushProcessor prepends processors to the beginning of the stack
        foreach (array_reverse($this->findAndSortTaggedServices('monolog.processor', $container)) as $reference) {
            $tags = $container->getDefinition((string) $reference)->getTag('monolog.processor');

            foreach ($tags as $tag) {
                if (!empty($tag['channel']) && !empty($tag['handler'])) {
                    throw new \InvalidArgumentException(\sprintf('you cannot specify both the "handler" and "channel" attributes for the "monolog.processor" tag on service "%s"', $reference));
                }

                if (!empty($tag['handler'])) {
                    $definition = $container->findDefinition(\sprintf('monolog.handler.%s', $tag['handler']));
                    $parentDef = $definition;
                    while (!$parentDef->getClass() && $parentDef instanceof ChildDefinition) {
                        $parentDef = $container->findDefinition($parentDef->getParent());
                    }
                    $class = $container->getParameterBag()->resolveValue($parentDef->getClass());
                    if (!method_exists($class, 'pushProcessor')) {
                        throw new \InvalidArgumentException(\sprintf('The "%s" handler does not accept processors', $tag['handler']));
                    }
                } elseif (!empty($tag['channel'])) {
                    if ('app' === $tag['channel']) {
                        $definition = $container->getDefinition('monolog.logger');
                    } else {
                        $definition = $container->getDefinition(\sprintf('monolog.logger.%s', $tag['channel']));
                    }
                } else {
                    $definition = $container->getDefinition('monolog.logger_prototype');
                }

                if (!empty($tag['method'])) {
                    $processor = [$reference, $tag['method']];
                } else {
                    // If no method is defined, fallback to use __invoke
                    $processor = $reference;
                }
                $definition->addMethodCall('pushProcessor', [$processor]);
            }
        }
    }
}
