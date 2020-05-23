<?php


namespace EasySwoole\Bridge;


use EasySwoole\Component\Process\Socket\UnixProcessConfig;
use Swoole\Server;

class Bridge
{
    private $socketFile;
    private $container;
    private $onStart;
    private $onException;

    function __construct(Container $container = null)
    {
        if(!$container){
            $container = new Container();
        }
        $this->container = $container;
    }

    function setOnStart(callable $call): Bridge
    {
        $this->onStart = $call;
        return $this;
    }

    function setOnException(callable $call): Bridge
    {
        $this->onException = $call;
        return $this;
    }

    function getCommandContainer():Container
    {
        return $this->container;
    }

    function attachServer(Server $server,string $serverName = 'EasySwoole')
    {
        $config = new UnixProcessConfig();
        $config->setSocketFile($this->socketFile);
        $config->setProcessName("{$serverName}.Bridge");
        $config->setProcessGroup("{$serverName}.Bridge");
        $config->setArg([
            'onStart'=>$this->onStart,
            'container'=>$this->container,
            'onException'=>$this->onException
        ]);
        $p = new BridgeProcess($config);
        $server->addProcess($p->getProcess());
    }



    /**
     * @return mixed
     */
    public function getSocketFile()
    {
        if(empty($this->socketFile)){
            $this->socketFile = sys_get_temp_dir().'/bridge.sock';
        }
        return $this->socketFile;
    }

    /**
     * @param mixed $socketFile
     */
    public function setSocketFile($socketFile): void
    {
        $this->socketFile = $socketFile;
    }
}