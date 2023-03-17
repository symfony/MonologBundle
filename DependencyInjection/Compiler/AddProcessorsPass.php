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
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers processors in Monolog loggers or handlers.
 *
 * @author Christophe Coevoet <stof@notk.org>
 *
 * @internal since 3.9.0
 */
class AddProcessorsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        if (!$container->hasDefinition('monolog.logger')) {
            return;
        }

        $processors = [];

        foreach ($container->findTaggedServiceIds('monolog.processor') as $id => $tags) {
            foreach ($tags as $tag) {
                if (!isset($tag['priority'])) {
                    $tag['priority'] = 0;
                }

                $processors[] = [
                    'id' => $id,
                    'tag' => $tag,
                ];
            }
        }

        // Sort by priority so that higher-prio processors are added last.
        // The effect is the monolog will call the higher-prio processors first
        usort(
            $processors,
            function (array $left, array $right) {
                return $left['tag']['priority'] - $right['tag']['priority'];
            }
        );

        foreach ($processors as $processor) {
            $tag = $processor['tag'];
            $id = $processor['id'];

            if (!empty($tag['channel']) && !empty($tag['handler'])) {
                throw new \InvalidArgumentException(sprintf('you cannot specify both the "handler" and "channel" attributes for the "monolog.processor" tag on service "%s"', $id));
            }

            if (!empty($tag['handler'])) {
                $definition = $container->findDefinition(sprintf('monolog.handler.%s', $tag['handler']));
                $parentDef = $definition;
                while (!$parentDef->getClass() && $parentDef instanceof ChildDefinition) {
                    $parentDef = $container->findDefinition($parentDef->getParent());
                }
                $class = $container->getParameterBag()->resolveValue($parentDef->getClass());
                if (!method_exists($class, 'pushProcessor')) {
                    throw new \InvalidArgumentException(sprintf('The "%s" handler does not accept processors', $tag['handler']));
                }
            } elseif (!empty($tag['channel'])) {
                if ('app' === $tag['channel']) {
                    $definition = $container->getDefinition('monolog.logger');
                } else {
                    $definition = $container->getDefinition(sprintf('monolog.logger.%s', $tag['channel']));
                }
            } else {
                $definition = $container->getDefinition('monolog.logger_prototype');
            }

            if (!empty($tag['method'])) {
                $processor = [new Reference($id), $tag['method']];
            } else {
                // If no method is defined, fallback to use __invoke
                $processor = new Reference($id);
            }
            $definition->addMethodCall('pushProcessor', [$processor]);
        }
    }
}
