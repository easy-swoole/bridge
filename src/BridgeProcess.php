<?php


namespace EasySwoole\Bridge;


use EasySwoole\Component\Process\Socket\AbstractUnixProcess;
use Swoole\Coroutine\Socket;

class BridgeProcess extends AbstractUnixProcess
{
    function onAccept(Socket $socket)
    {
        // TODO: Implement onAccept() method.
    }

}