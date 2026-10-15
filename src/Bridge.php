<?php


namespace EasySwoole\Bridge;


use EasySwoole\Component\Process\Socket\UnixProcessConfig;
use EasySwoole\Socket\Tools\Client;
use EasySwoole\Socket\Tools\Protocol;
use Swoole\Server;

class Bridge
{
    private const MAX_UNIX_SOCKET_PATH_LENGTH = 103;

    private string $socketFile;
    private Container $container;
    private mixed $onException = null;
    private string $serverName;
    private bool $hasAttach = false;

    function __construct(Container|null $container = null, string $serverName = 'EasySwoole')
    {
        $serverName = trim($serverName);
        if ($serverName === '' || str_contains($serverName, "\0") || str_contains($serverName, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('serverName must be a non-empty file-name-safe string');
        }
        $this->container = $container ?? new Container();
        $this->serverName = $serverName;
    }


    function setOnException(callable $call): Bridge
    {
        if ($this->hasAttach) {
            throw new \LogicException('onException cannot be changed after attachServer()');
        }
        $this->onException = $call;
        return $this;
    }

    function getCommandContainer():Container
    {
        return $this->container;
    }

    function attachServer(Server $server): void
    {
        if($this->hasAttach){
            return;
        }
        $config = new UnixProcessConfig();
        $config->setSocketFile($this->getSocketFile());
        $config->setProcessName("{$this->serverName}.Bridge");
        $config->setProcessGroup("{$this->serverName}.Bridge");
        $config->setArg([
            'container'=>$this->container,
            'onException'=>$this->onException
        ]);
        $p = new BridgeProcess($config);
        $server->addProcess($p->getProcess());
        $this->hasAttach = true;
    }

    function send(Package $package,float $timeout = 3.0): Package
    {
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be greater than 0');
        }
        $deadline = microtime(true) + $timeout;

        try {
            $payload = serialize($package);
        } catch (\Throwable) {
            return $this->errorPackage(StatusEnum::REQUEST_SERIALIZE_ERROR, 'serialize request package error');
        }
        if (strlen($payload) > Package::MAX_PAYLOAD_LENGTH) {
            return $this->errorPackage(StatusEnum::REQUEST_TOO_LARGE, 'request package is too large');
        }

        $remaining = $this->remainingTime($deadline);
        if ($remaining === null) {
            return $this->errorPackage(StatusEnum::CONNECT_ERROR, 'connect to server timeout');
        }
        $client = new Client($this->getSocketFile(), null, $remaining);
        try {
            if(!$client->client()->isConnected()){
                return $this->errorPackage(StatusEnum::CONNECT_ERROR, 'connect to server fail');
            }

            $packet = Protocol::pack($payload);
            $socket = $client->client()->exportSocket();
            $remaining = $this->remainingTime($deadline);
            if ($remaining === null) {
                return $this->errorPackage(StatusEnum::SEND_ERROR, 'send package to server timeout');
            }
            $sent = $socket?->sendAll($packet, $remaining);
            if ($sent !== strlen($packet)) {
                return $this->errorPackage(StatusEnum::SEND_ERROR, 'send package to server error');
            }
            $remaining = $this->remainingTime($deadline);
            if ($remaining === null) {
                return $this->errorPackage(StatusEnum::RECEIVE_ERROR, 'receive package from server timeout');
            }
            $ret = $client->recv($remaining);
        } finally {
            $client->close();
        }

        if(empty($ret)){
            return $this->errorPackage(StatusEnum::RECEIVE_ERROR, 'receive package from server error');
        }

        return $this->deserializeResponse($ret, $deadline);
    }

    private function deserializeResponse(string $payload, float $deadline): Package
    {
        if ($this->remainingTime($deadline) === null) {
            return $this->errorPackage(StatusEnum::RECEIVE_ERROR, 'deserialize response timeout');
        }
        try {
            $package = @unserialize($payload);
        } catch (\Throwable) {
            $package = null;
        }
        if ($this->remainingTime($deadline) === null) {
            return $this->errorPackage(StatusEnum::RECEIVE_ERROR, 'deserialize response timeout');
        }
        if(!$package instanceof Package){
            return $this->errorPackage(StatusEnum::INVALID_PACKAGE, 'invalid package from server');
        }
        return $package;
    }

    private function errorPackage(StatusEnum $status, string $message): Package
    {
        $package = new Package();
        $package->setMsg($message);
        $package->setStatus($status);
        return $package;
    }

    private function remainingTime(float $deadline): ?float
    {
        $remaining = $deadline - microtime(true);
        return $remaining > 0 ? $remaining : null;
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
        if(isset($this->socketFile)) {
            return $this->socketFile;
        }
        $this->socketFile = sys_get_temp_dir()."/{$this->serverName}.bridge.sock";
        $this->validateSocketFile($this->socketFile);
        return $this->socketFile;
    }


    public function setSocketFile(string $socketFile): Bridge
    {
        if ($this->hasAttach) {
            throw new \LogicException('socketFile cannot be changed after attachServer()');
        }
        $this->validateSocketFile($socketFile);
        $this->socketFile = $socketFile;
        return $this;
    }

    private function validateSocketFile(string $socketFile): void
    {
        if (trim($socketFile) === '' || str_contains($socketFile, "\0")) {
            throw new \InvalidArgumentException('socketFile must be a non-empty path without null bytes');
        }
        if (strlen($socketFile) > self::MAX_UNIX_SOCKET_PATH_LENGTH) {
            throw new \InvalidArgumentException(
                'socketFile must not exceed ' . self::MAX_UNIX_SOCKET_PATH_LENGTH . ' bytes'
            );
        }
    }
}
