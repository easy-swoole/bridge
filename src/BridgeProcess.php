<?php


namespace EasySwoole\Bridge;


use EasySwoole\Component\Process\Socket\AbstractUnixProcess;
use EasySwoole\Socket\Tools\Protocol;
use Swoole\Coroutine\Socket;

class BridgeProcess extends AbstractUnixProcess
{
    private Container $container;
    private mixed $onException;
    private int $maxPacketLength = 1024 * 1024;

    function run($arg): void
    {
        $this->container = $arg['container'];
        $this->onException = $arg['onException'];
        $this->maxPacketLength = $arg['maxPacketLength'];
        parent::run($arg);
    }

    function onAccept(Socket $socket)
    {
        $data = $this->readPackage($socket);
        if ($data === null) {
            $responsePackage = new Package();
            $responsePackage->setStatus(StatusEnum::INVALID_PACKAGE);
            $responsePackage->setMsg('receive package from client error');
            $this->writeResponse($socket, $responsePackage);
            return null;
        }
        /**
         * @var $request Package
         */
        try {
            $request = @unserialize($data);
        } catch (\Throwable) {
            $request = null;
        }

        if (!$request instanceof Package || trim($request->getCommand()) === '') {
            $responsePackage = new Package();
            $responsePackage->setStatus(StatusEnum::INVALID_PACKAGE);
            $responsePackage->setMsg('invalid package or empty command');
            $this->writeResponse($socket, $responsePackage);
            return null;
        }

        $command = $this->container->get($request->getCommand());
        if (!$command instanceof CommandInterface) {
            $responsePackage = new Package();
            $responsePackage->setStatus(StatusEnum::COMMAND_NOT_EXIST);
            $responsePackage->setMsg("command {$request->getCommand()} not exist");
            $this->writeResponse($socket, $responsePackage);
            return null;
        }
        $responsePackage = new Package();
        try{
            //结果在闭包中更改
            $responsePackage->setStatus(StatusEnum::SUCCESS);
            $request->setClientSocket($socket);
            $command->exec($request,$responsePackage);
        }catch (\Throwable $throwable){
            $responsePackage->setStatus(StatusEnum::COMMAND_EXEC_ERROR);
            $responsePackage->setMsg($throwable->getMessage());
            throw $throwable;
        } finally {
            $this->writeResponse($socket, $responsePackage);
        }
    }

    private function writeResponse(Socket $socket, Package $responsePackage): void
    {
        try {
            Protocol::socketWriter($socket, $this->serializeResponse($responsePackage));
        } finally {
            $socket->close();
        }
    }

    private function serializeResponse(Package $responsePackage): string
    {
        try {
            $payload = serialize($responsePackage);
        } catch (\Throwable) {
            return $this->serializeErrorPackage(
                StatusEnum::RESPONSE_SERIALIZE_ERROR,
                'serialize response package error'
            );
        }
        if (strlen($payload) > $this->maxPayloadLength()) {
            return $this->serializeErrorPackage(StatusEnum::RESPONSE_TOO_LARGE, 'response package is too large');
        }
        return $payload;
    }

    private function serializeErrorPackage(StatusEnum $status, string $message): string
    {
        $fallback = new Package();
        $fallback->setStatus($status);
        $fallback->setMsg($message);
        return serialize($fallback);
    }

    private function readPackage(Socket $socket, float $timeout = 3.0): ?string
    {
        $header = $socket->recvAll(4, $timeout);
        if (!is_string($header) || strlen($header) !== 4) {
            return null;
        }

        $length = Protocol::packDataLength($header);
        if ($length <= 0 || $length > $this->maxPayloadLength()) {
            return null;
        }

        $data = $socket->recvAll($length, $timeout);
        if (!is_string($data) || strlen($data) !== $length) {
            return null;
        }

        return $data;
    }

    private function maxPayloadLength(): int
    {
        return $this->maxPacketLength - 4;
    }

    protected function onException(\Throwable $throwable, ...$args): void
    {
        if($this->onException !== null){
            call_user_func($this->onException, $throwable);
        }else{
            throw $throwable;
        }
    }
}
