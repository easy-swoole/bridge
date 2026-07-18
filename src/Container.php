<?php


namespace EasySwoole\Bridge;


class Container
{
    private array $container = [];

    public function set(CommandInterface $command,bool $cover = false):void
    {
        if(!isset($this->container[strtolower($command->commandName())]) || $cover){
            $this->container[strtolower($command->commandName())] = $command;
        }
    }

    function get($key): CommandInterface|null
    {
        $key = strtolower($key);
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