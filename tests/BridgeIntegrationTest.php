<?php

declare(strict_types=1);

namespace EasySwoole\Bridge\Tests;

use EasySwoole\Bridge\Bridge;
use EasySwoole\Bridge\BridgeProcess;
use EasySwoole\Bridge\CommandInterface;
use EasySwoole\Bridge\Container;
use EasySwoole\Bridge\Package;
use EasySwoole\Bridge\StatusEnum;
use EasySwoole\Component\Process\Socket\UnixProcessConfig;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Process;

final class BridgeIntegrationTest extends TestCase
{
    private string $socketFile;
    private ?int $pid = null;
    private int $maxPacketLength = 1024 * 1024;

    protected function setUp(): void
    {
        $this->socketFile = sys_get_temp_dir() . '/bridge-integration-' . bin2hex(random_bytes(8)) . '.sock';
    }

    protected function tearDown(): void
    {
        if ($this->pid !== null && Process::kill($this->pid, 0)) {
            Process::kill($this->pid, SIGTERM);
            Process::wait(true);
        }
        @unlink($this->socketFile);
    }

    public function testTwoProcessesCanExchangeAPackage(): void
    {
        $this->startServer(new class implements CommandInterface {
            public function commandName(): string
            {
                return 'echo';
            }

            public function exec(Package $request, Package $responsePackage): void
            {
                $responsePackage->setArgs($request->getArgs());
                $responsePackage->setMsg('ok');
            }
        });

        $result = $this->call('echo', ['value' => 42]);

        self::assertSame(StatusEnum::SUCCESS, $result->getStatus());
        self::assertSame(['value' => 42], $result->getArgs());
        self::assertSame('ok', $result->getMsg());
    }

    public function testCommandExceptionIsReturnedToClient(): void
    {
        $this->startServer(new class implements CommandInterface {
            private int $calls = 0;

            public function commandName(): string
            {
                return 'fail';
            }

            public function exec(Package $request, Package $responsePackage): void
            {
                ++$this->calls;
                if ($this->calls === 1) {
                    throw new \RuntimeException('expected command failure');
                }
                $responsePackage->setMsg('recovered');
            }
        }, static function (\Throwable $throwable): void {
        });

        $result = $this->call('fail');

        self::assertSame(StatusEnum::COMMAND_EXEC_ERROR, $result->getStatus());
        self::assertSame('expected command failure', $result->getMsg());

        $recovered = $this->call('fail');
        self::assertSame(StatusEnum::SUCCESS, $recovered->getStatus());
        self::assertSame('recovered', $recovered->getMsg());
    }

    public function testReceiveTimeoutReturnsPackageError(): void
    {
        $this->startServer(new class implements CommandInterface {
            public function commandName(): string
            {
                return 'slow';
            }

            public function exec(Package $request, Package $responsePackage): void
            {
                Coroutine::sleep(0.2);
            }
        });

        $startedAt = microtime(true);
        $result = $this->call('slow', null, 0.05);
        $elapsed = microtime(true) - $startedAt;

        self::assertSame(StatusEnum::RECEIVE_ERROR, $result->getStatus());
        self::assertSame('receive package from server error', $result->getMsg());
        self::assertLessThan(0.15, $elapsed);
    }

    public function testCustomPacketLimitSupportsPayloadLargerThanDefault(): void
    {
        $body = str_repeat('x', 1_200_000);
        $this->startServer(new class implements CommandInterface {
            public function commandName(): string
            {
                return 'large-echo';
            }

            public function exec(Package $request, Package $responsePackage): void
            {
                $responsePackage->setArgs($request->getArgs());
            }
        }, null, 2 * 1024 * 1024);

        $result = $this->call('large-echo', $body, 2.0);

        self::assertSame(StatusEnum::SUCCESS, $result->getStatus());
        self::assertSame(strlen($body), strlen($result->getArgs()));
    }

    private function startServer(
        CommandInterface $command,
        ?callable $onException = null,
        int $maxPacketLength = 1024 * 1024
    ): void
    {
        $this->maxPacketLength = $maxPacketLength;
        $container = new Container();
        $container->set($command);

        $config = new UnixProcessConfig();
        $config->setSocketFile($this->socketFile);
        $config->setProcessName('Bridge.PhpUnit');
        $config->setProcessGroup('Bridge.PhpUnit');
        $config->setArg([
            'container' => $container,
            'onException' => $onException,
            'maxPacketLength' => $maxPacketLength,
        ]);

        $process = new BridgeProcess($config);
        $pid = $process->getProcess()->start();
        self::assertIsInt($pid);
        self::assertGreaterThan(0, $pid);
        $this->pid = $pid;

        $deadline = microtime(true) + 2.0;
        while (!file_exists($this->socketFile) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        self::assertFileExists($this->socketFile, 'Bridge child process did not create its socket in time');
    }

    private function call(string $command, mixed $args = null, float $timeout = 1.0): Package
    {
        $result = null;
        Coroutine\run(function () use ($command, $args, $timeout, &$result): void {
            $bridge = (new Bridge())->setSocketFile($this->socketFile);
            $bridge->getConfig()->setMaxPacketLength($this->maxPacketLength);
            $result = $bridge->call($command, $args, $timeout);
        });

        self::assertInstanceOf(Package::class, $result);
        return $result;
    }
}
