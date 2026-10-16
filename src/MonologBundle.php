<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MonologBundle;

use Symfony\Bundle\MonologBundle\DependencyInjection\Compiler\AddHandlersToManagerPass;
use Symfony\Bundle\MonologBundle\DependencyInjection\Compiler\AddProcessorsPass;
use Symfony\Bundle\MonologBundle\DependencyInjection\Compiler\LoggerChannelPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
final class MonologBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new LoggerChannelPass());
        $container->addCompilerPass(new AddProcessorsPass());
        $container->addCompilerPass(new AddHandlersToManagerPass());
    }

    public function shutdown(): void
    {
        parent::shutdown();

        // Container shutdown callbacks are available since Symfony 8.2.
        if (is_a(ServicesResetterInterface::class, 'Symfony\Contracts\Service\ShutdownInterface', true)) {
            return;
        }

        if ($this->container?->has('monolog.handler_lifecycle_manager')) {
            $this->container->get('monolog.handler_lifecycle_manager')->close();
        }
    }
}
