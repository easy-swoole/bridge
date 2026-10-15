<?php


namespace EasySwoole\Bridge;


class Container
{
    private array $container = [];

    public function set(CommandInterface $command,bool $cover = false):void
    {
        $name = strtolower(trim($command->commandName()));
        if ($name === '') {
            throw new \InvalidArgumentException('command name must not be empty');
        }
        if(!isset($this->container[$name]) || $cover){
            $this->container[$name] = $command;
        }
    }

    function get(string $key): CommandInterface|null
    {
        $key = strtolower(trim($key));
        if (isset($this->container[$key])) {
            return $this->container[$key];
        } else {
            return null;
        }
    }

    function all():array
    {
        return $this->container;
    }
}
