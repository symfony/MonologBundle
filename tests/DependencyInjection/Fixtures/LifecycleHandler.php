<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MonologBundle\Tests\DependencyInjection\Fixtures;

use Monolog\Handler\TestHandler;

class LifecycleHandler extends TestHandler
{
    public int $resets = 0;
    public int $closes = 0;

    public function reset(): void
    {
        ++$this->resets;
        parent::reset();
    }

    public function close(): void
    {
        ++$this->closes;
    }
}
