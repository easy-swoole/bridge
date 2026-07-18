<?php


namespace EasySwoole\Bridge;


use Swoole\Coroutine\Socket;

interface CommandInterface
{
    public function commandName():string;
    public function exec(Package $request,Package $responsePackage);
}
