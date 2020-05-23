<?php


namespace EasySwoole\Bridge;


use Swoole\Server;

class Bridge
{
    private $container;

    function __construct(Container $container = null)
    {
        if(!$container){
            $container = new Container();
        }
        $this->container = $container;
    }

    function getCommandContainer():Container
    {
        return $this->container;
    }

    function attachServer(Server $server)
    {

    }
}