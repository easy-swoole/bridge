<?php


namespace EasySwoole\Bridge;


use EasySwoole\Component\Process\Socket\AbstractUnixProcess;
use EasySwoole\Socket\Tools\Protocol;
use Swoole\Coroutine\Socket;

class BridgeProcess extends AbstractUnixProcess
{
    private Container $container;
    private mixed $onException;

    function run($arg)
    {
        $this->container = $arg['container'];
        $this->onException = $arg['onException'];
        parent::run($arg);
    }

    function onAccept(Socket $socket)
    {
        $data = Protocol::socketReader($socket, 3);
        if ($data === null) {
            $responsePackage = new Package();
            $responsePackage->setStatus(StatusEnum::PACKAGE_ERROR);
            $responsePackage->setMsg('receive package from client error');
            Protocol::socketWriter($socket, serialize($responsePackage));
            $socket->close();
            return null;
        }
        /**
         * @var $request Package
         */
        $request = unserialize($data);

        $command = $this->container->get($request->getCommand());
        if (!$command instanceof CommandInterface) {
            $responsePackage = new Package();
            $responsePackage->setStatus(StatusEnum::COMMAND_NOT_EXIST);
            $responsePackage->setMsg("command {$request->getCommand()} not exist");
            Protocol::socketWriter($socket, serialize($responsePackage));
            $socket->close();
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
            $this->onException($throwable);
        } finally {
            Protocol::socketWriter($socket,serialize($responsePackage));
            $socket->close();
        }
    }

    protected function onException(\Throwable $throwable, ...$args)
    {
        if($this->onException){
            call_user_func($this->onException,$throwable);
        }else{
            throw $throwable;
        }
    }
}
