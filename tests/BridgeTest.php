<?php

declare(strict_types=1);

namespace EasySwoole\Bridge\Tests;

use EasySwoole\Bridge\Bridge;
use EasySwoole\Bridge\Package;
use EasySwoole\Bridge\StatusEnum;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class BridgeTest extends TestCase
{
    public function testDefaultServerNameDeterminesSocketFile(): void
    {
        $bridge = new class extends Bridge {
            public function socketFile(): string
            {
                return $this->getSocketFile();
            }
        };

        self::assertSame(sys_get_temp_dir() . '/EasySwoole.bridge.sock', $bridge->socketFile());
    }

    public function testCustomServerNameDeterminesSocketFile(): void
    {
        $bridge = new class(null, '  Worker  ') extends Bridge {
            public function socketFile(): string
            {
                return $this->getSocketFile();
            }
        };

        self::assertSame(sys_get_temp_dir() . '/Worker.bridge.sock', $bridge->socketFile());
    }

    public function testServerNameMustNotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('serverName must be a non-empty file-name-safe string');

        new Bridge(null, '   ');
    }

    public function testServerNameMustNotContainDirectorySeparator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Bridge(null, 'group/worker');
    }

    public function testSocketFileMustNotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('socketFile must be a non-empty path');

        (new Bridge())->setSocketFile('   ');
    }

    public function testSocketFileMustNotContainNullBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Bridge())->setSocketFile("/tmp/bridge\0.sock");
    }

    public function testSocketFileMustFitUnixSocketPathLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('socketFile must not exceed 103 bytes');

        (new Bridge())->setSocketFile('/tmp/' . str_repeat('x', 100));
    }

    public function testGeneratedSocketFileMustFitUnixSocketPathLimit(): void
    {
        $bridge = new class(null, str_repeat('x', 100)) extends Bridge {
            public function socketFile(): string
            {
                return $this->getSocketFile();
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('socketFile must not exceed 103 bytes');

        $bridge->socketFile();
    }

    public function testExceptionCallbackCannotChangeAfterAttach(): void
    {
        $bridge = new Bridge();
        $property = new ReflectionProperty(Bridge::class, 'hasAttach');
        $property->setValue($bridge, true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('onException cannot be changed after attachServer()');

        $bridge->setOnException(static function (): void {
        });
    }

    public function testTimeoutMustBePositive(): void
    {
        $bridge = (new Bridge())->setSocketFile('/tmp/bridge-test.sock');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout must be greater than 0');

        $bridge->send(new Package(), 0);
    }

    public function testUnserializableRequestReturnsPackageError(): void
    {
        $bridge = (new Bridge())->setSocketFile('/tmp/bridge-test.sock');
        $package = new Package();
        $package->setArgs(static fn (): bool => true);

        $result = $bridge->send($package);

        self::assertSame(StatusEnum::REQUEST_SERIALIZE_ERROR, $result->getStatus());
        self::assertSame('serialize request package error', $result->getMsg());
    }

    public function testOversizedRequestReturnsPackageError(): void
    {
        $bridge = (new Bridge())->setSocketFile('/tmp/bridge-test.sock');
        $package = new Package();
        $package->setArgs(str_repeat('x', Package::MAX_PAYLOAD_LENGTH));

        $result = $bridge->send($package);

        self::assertSame(StatusEnum::REQUEST_TOO_LARGE, $result->getStatus());
        self::assertSame('request package is too large', $result->getMsg());
    }

    public function testResponseDeserializationMustFinishBeforeDeadline(): void
    {
        $response = new Package();
        $response->setStatus(StatusEnum::SUCCESS);
        $response->setArgs(new SlowUnserializePayload());
        $deserialize = new ReflectionMethod(Bridge::class, 'deserializeResponse');

        $result = $deserialize->invoke(new Bridge(), serialize($response), microtime(true) + 0.005);

        self::assertInstanceOf(Package::class, $result);
        self::assertSame(StatusEnum::RECEIVE_ERROR, $result->getStatus());
        self::assertSame('deserialize response timeout', $result->getMsg());
    }

    public function testResponseIsNotDeserializedAfterDeadline(): void
    {
        $deserialize = new ReflectionMethod(Bridge::class, 'deserializeResponse');

        $result = $deserialize->invoke(new Bridge(), 'invalid payload', microtime(true) - 1);

        self::assertInstanceOf(Package::class, $result);
        self::assertSame(StatusEnum::RECEIVE_ERROR, $result->getStatus());
        self::assertSame('deserialize response timeout', $result->getMsg());
    }
}

final class SlowUnserializePayload
{
    public function __serialize(): array
    {
        return [];
    }

    public function __unserialize(array $data): void
    {
        usleep(20_000);
    }
}
