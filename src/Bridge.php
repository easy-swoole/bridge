<?php


namespace EasySwoole\Bridge;


use EasySwoole\Component\Process\Socket\UnixProcessConfig;
use EasySwoole\Socket\Tools\Client;
use Swoole\Server;

class Bridge
{
    private string $socketFile;
    private Container $container;
    private mixed $onException = null;
    private string $serverName;
    private bool $hasAttach = false;

    function __construct(Container|null $container = null)
    {
        if(!$container){
            $container = new Container();
        }
        $this->container = $container;
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

    function attachServer(Server $server,string $serverName = 'EasySwoole'): void
    {
        if($this->hasAttach){
            return;
        }
        $this->hasAttach = true;
        $this->serverName = $serverName;
        $config = new UnixProcessConfig();
        $config->setSocketFile($this->getSocketFile());
        $config->setProcessName("{$serverName}.Bridge");
        $config->setProcessGroup("{$serverName}.Bridge");
        $config->setArg([
            'container'=>$this->container,
            'onException'=>$this->onException
        ]);
        $p = new BridgeProcess($config);
        $server->addProcess($p->getProcess());
    }

    function send(Package $package,float $timeout = 3.0): Package
    {
        $client = new Client($this->getSocketFile());
        if(!$client->client()->isConnected()){
            $package = new Package();
            $package->setMsg('connect to server fail');
            $package->setStatus(StatusEnum::CONNECT_ERROR);
            return $package;
        }

        $client->send(serialize($package));
        $ret = $client->recv($timeout);
        $client->close();

        if(!empty($ret)){
            $package = unserialize($ret);
        }else{
            $package = null;
        }

        if(!$package instanceof Package){
            $package = new Package();
            $package->setMsg('receive package from server error');
            $package->setStatus(StatusEnum::PACKAGE_ERROR);
        }
        return $package;
    }

    function call(string $command,$arg = null,float $timeout = 3.0):Package
    {
        $package = new Package();
        $package->setCommand($command);
        $package->setArgs($arg);
        return $this->send($package,$timeout);
    }


    protected function getSocketFile():string
    {
        if(empty($this->socketFile)){
            $this->socketFile = sys_get_temp_dir()."/{$this->serverName}.bridge.sock";
        }
        return $this->socketFile;
    }


    public function setSocketFile(string $socketFile): Bridge
    {
        $this->socketFile = $socketFile;
        return $this;
    }
}
