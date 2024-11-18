<?php


namespace Symfony\Bundle\MonologBundle;


use Monolog\Handler\HandlerInterface;

class HandlerLifecycleManager
{
    /**
     * @var HandlerInterface[]
     */
    private $handlers;

    public function __construct(\IteratorAggregate $handlers)
    {
        $this->handlers = $handlers;
    }

    public function close()
    {
        foreach ($this->handlers as $handler) {
            $handler->close();
        }
    }
}
