<?php


namespace EasySwoole\Bridge;


use EasySwoole\Component\Process\Socket\UnixProcessConfig;
use EasySwoole\Socket\Tools\Protocol;
use Swoole\Coroutine\Client;
use Swoole\Server;

class Bridge
{
    private const MAX_UNIX_SOCKET_PATH_LENGTH = 103;

    private string $socketFile;
    private Container $container;
    private Config $config;
    private mixed $onException = null;
    private bool $hasAttach = false;

    function __construct(Container|null $container = null)
    {
        $this->container = $container ?? new Container();
        $this->config = new Config();
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

    function getConfig(): Config
    {
        return $this->config;
    }

    function attachServer(Server $server): void
    {
        if($this->hasAttach){
            return;
        }
        $config = new UnixProcessConfig();
        $config->setSocketFile($this->getSocketFile());
        $serverName = $this->config->getServerName();
        $config->setProcessName("{$serverName}.Bridge");
        $config->setProcessGroup("{$serverName}.Bridge");
        $config->setArg([
            'container'=>$this->container,
            'onException'=>$this->onException,
            'maxPacketLength'=>$this->config->getMaxPacketLength(),
        ]);
        $p = new BridgeProcess($config);
        $server->addProcess($p->getProcess());
        $this->hasAttach = true;
        $this->config->lock();
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
        if (strlen($payload) > $this->maxPayloadLength()) {
            return $this->errorPackage(StatusEnum::REQUEST_TOO_LARGE, 'request package is too large');
        }

        $remaining = $this->remainingTime($deadline);
        if ($remaining === null) {
            return $this->errorPackage(StatusEnum::CONNECT_ERROR, 'connect to server timeout');
        }
        $client = new Client(SWOOLE_UNIX_STREAM);
        $client->set([
            'open_length_check' => true,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
            'package_max_length' => $this->config->getMaxPacketLength(),
        ]);
        try {
            if (!$client->connect($this->getSocketFile(), 0, $remaining)) {
                return $this->errorPackage(StatusEnum::CONNECT_ERROR, 'connect to server fail');
            }

            $packet = Protocol::pack($payload);
            $socket = $client->exportSocket();
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

        return $this->deserializeResponse(Protocol::unpack($ret), $deadline);
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

    private function maxPayloadLength(): int
    {
        return $this->config->getMaxPacketLength() - 4;
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
        $socketFile = sys_get_temp_dir()."/{$this->config->getServerName()}.bridge.sock";
        $this->validateSocketFile($socketFile);
        return $socketFile;
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
