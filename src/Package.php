<?php


namespace EasySwoole\Bridge;


use Swoole\Coroutine\Socket;

class Package
{
    protected StatusEnum $status = StatusEnum::UN_INIT;
    protected string $command = '';
    protected mixed $args = null;
    protected string|null $msg = null;

    protected Socket|null $clientSocket  = null;


    public function getStatus(): StatusEnum
    {
        return $this->status;
    }


    public function setStatus(StatusEnum $status): void
    {
        $this->status = $status;
    }


    public function getCommand():string
    {
        return $this->command;
    }


    public function setCommand(string $command): void
    {
        if (trim($command) === '') {
            throw new \InvalidArgumentException('command must not be empty');
        }
        $this->command = trim($command);
    }

    public function getArgs():mixed
    {
        return $this->args;
    }


    public function setArgs(mixed $args): void
    {
        $this->args = $args;
    }


    public function getMsg():string|null
    {
        return $this->msg;
    }


    public function setMsg(string|null $msg): void
    {
        $this->msg = $msg;
    }

    public function getClientSocket():Socket|null
    {
        return $this->clientSocket;
    }

    public function setClientSocket(Socket $clientSocket): void{
        $this->clientSocket = $clientSocket;
    }
}
