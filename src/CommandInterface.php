<?php


namespace EasySwoole\Bridge;


interface CommandInterface
{
    public function commandName():string;
    public function exec(...$arg);
}