# EasySwoole Bridge

EasySwoole Bridge 是一个基于 Unix Domain Socket 的进程间通信组件，用于连接隶属于不同主进程的 PHP/Swoole 应用。

通信采用长度头协议：4 字节网络字节序长度头，加 PHP 序列化后的 `Package` 数据。单个完整数据包最大为 1 MiB。

## 环境要求

- PHP 8.1 或更高版本
- Swoole 5.1 或更高版本
- Unix Domain Socket 环境

## 安装

```bash
composer require easyswoole/bridge
```

## 定义命令

每个命令需要实现 `CommandInterface`：

```php
<?php

use EasySwoole\Bridge\CommandInterface;
use EasySwoole\Bridge\Package;

final class PingCommand implements CommandInterface
{
    public function commandName(): string
    {
        return 'ping';
    }

    public function exec(Package $request, Package $responsePackage): void
    {
        $responsePackage->setArgs([
            'request' => $request->getArgs(),
            'time' => time(),
        ]);
        $responsePackage->setMsg('pong');
    }
}
```

命令名不区分大小写，注册和调用时会自动去除两端空白。空命令名会被拒绝。

## 服务端

命令应在 Swoole 服务启动前完成注册，然后将 Bridge 进程附加到服务：

```php
<?php

use EasySwoole\Bridge\Bridge;
use Swoole\Server;

$server = new Server('127.0.0.1', 9501);
$server->on('Receive', static function (
    Server $server,
    int $fd,
    int $reactorId,
    string $data
): void {
    // 处理主服务自己的 TCP 请求。
});

$bridge = new Bridge();
$bridge->getConfig()->setServerName('OrderService');
$bridge->getCommandContainer()->set(new PingCommand());
$bridge->setOnException(static function (Throwable $throwable): void {
    error_log((string) $throwable);
});
$bridge->attachServer($server);

$server->start();
```

默认 socket 路径为：

```text
{系统临时目录}/{serverName}.bridge.sock
```

也可以在 `attachServer()` 之前显式指定路径：

```php
$bridge->setSocketFile('/var/run/my-app/order.bridge.sock');
```

数据包上限也可以在构造时调整。通信双方必须使用相同的上限：

```php
$bridge = new Bridge();
$bridge->getConfig()
    ->setServerName('OrderService')
    ->setMaxPacketLength(2 * 1024 * 1024);
```

`attachServer()` 执行后，Config、异常回调和 socket 路径不可再修改。服务启动后，命令容器已存在于独立子进程内；运行期间修改主进程中的容器不会同步到 Bridge 子进程。

## 客户端调用

客户端必须使用与服务端相同的 `serverName` 或 socket 路径：

```php
<?php

use EasySwoole\Bridge\Bridge;
use EasySwoole\Bridge\StatusEnum;
use Swoole\Coroutine;

Coroutine\run(static function (): void {
    $bridge = new Bridge();
    $bridge->getConfig()->setServerName('OrderService');
    $result = $bridge->call('ping', ['id' => 42], timeout: 3.0);

    if ($result->getStatus() !== StatusEnum::SUCCESS) {
        throw new RuntimeException($result->getMsg() ?? 'Bridge call failed');
    }

    var_dump($result->getArgs());
});
```

Bridge 使用 Swoole 协程客户端，建议在协程环境中调用。

也可以自行构造 `Package`：

```php
use EasySwoole\Bridge\Package;

$request = new Package();
$request->setCommand('ping');
$request->setArgs(['id' => 42]);

$response = $bridge->send($request, timeout: 3.0);
```

## 状态码

| 状态 | 含义 |
| --- | --- |
| `UN_INIT` | Package 尚未初始化 |
| `SUCCESS` | 命令执行成功 |
| `CONNECT_ERROR` | 连接失败或连接前已超时 |
| `SEND_ERROR` | 请求发送失败或发送超时 |
| `RECEIVE_ERROR` | 响应接收或反序列化超时 |
| `INVALID_PACKAGE` | 收到的数据不是有效的 Package |
| `REQUEST_SERIALIZE_ERROR` | 请求无法序列化 |
| `REQUEST_TOO_LARGE` | 请求超过允许的正文大小 |
| `RESPONSE_SERIALIZE_ERROR` | 响应无法序列化 |
| `RESPONSE_TOO_LARGE` | 响应超过允许的正文大小 |
| `COMMAND_NOT_EXIST` | 服务端没有注册该命令 |
| `COMMAND_EXEC_ERROR` | 命令执行过程中抛出异常 |

## 超时语义

`call()` 和 `send()` 的 `$timeout` 是连接、发送、接收及响应反序列化共享的总时间预算，而不是每个阶段分别计算。

PHP 无法安全中断正在执行的同步 `__unserialize()`。Bridge 会在反序列化前后检查截止时间并丢弃超时结果，但无法保证耗时的用户反序列化代码在截止时间到达时立即停止。

## 异常处理

命令抛出异常时，客户端会先收到 `COMMAND_EXEC_ERROR`。

- 注册了 `onException`：异常交给回调处理，Bridge 子进程继续运行。
- 未注册 `onException`：异常继续抛出，Bridge 子进程退出。
- 异常回调自身抛出异常：回调只执行一次，随后异常继续抛出。

## 协议限制

- 完整数据包默认最大为 1 MiB，可通过 `$bridge->getConfig()->setMaxPacketLength()` 调整。
- 长度头固定为 4 字节。
- 最大序列化正文为 `maxPacketLength - 4 字节`。
- Unix Socket 路径最长为 103 字节。
- 请求参数和响应数据必须能够被 PHP `serialize()` 正确处理。
- socket 路径的唯一性、权限和相对路径解析由调用方负责。

## 测试

```bash
composer install
vendor/bin/phpunit
```

测试包含参数校验、序列化边界、错误状态、超时，以及真实父子进程之间的 Unix Socket 通信。
