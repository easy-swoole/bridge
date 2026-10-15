<?php

declare(strict_types=1);

namespace EasySwoole\Bridge\Tests;

use EasySwoole\Bridge\BridgeProcess;
use EasySwoole\Bridge\Package;
use EasySwoole\Bridge\StatusEnum;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class BridgeProcessTest extends TestCase
{
    private BridgeProcess $process;
    private ReflectionMethod $serializeResponse;

    protected function setUp(): void
    {
        $this->process = (new ReflectionClass(BridgeProcess::class))->newInstanceWithoutConstructor();
        $this->serializeResponse = new ReflectionMethod(BridgeProcess::class, 'serializeResponse');
    }

    public function testSerializableResponseIsPreserved(): void
    {
        $response = new Package();
        $response->setStatus(StatusEnum::SUCCESS);
        $response->setArgs(['ok' => true]);

        $result = $this->serializeAndRestore($response);

        self::assertSame(StatusEnum::SUCCESS, $result->getStatus());
        self::assertSame(['ok' => true], $result->getArgs());
    }

    public function testUnserializableResponseFallsBackToPackageError(): void
    {
        $response = new Package();
        $response->setStatus(StatusEnum::SUCCESS);
        $response->setArgs(static fn (): bool => true);

        $result = $this->serializeAndRestore($response);

        self::assertSame(StatusEnum::RESPONSE_SERIALIZE_ERROR, $result->getStatus());
        self::assertSame('serialize response package error', $result->getMsg());
        self::assertNull($result->getArgs());
    }

    public function testOversizedResponseFallsBackToPackageError(): void
    {
        $response = new Package();
        $response->setStatus(StatusEnum::SUCCESS);
        $response->setArgs(str_repeat('x', 1024 * 1024));

        $result = $this->serializeAndRestore($response);

        self::assertSame(StatusEnum::RESPONSE_TOO_LARGE, $result->getStatus());
        self::assertSame('response package is too large', $result->getMsg());
    }

    public function testRegisteredExceptionCallbackReceivesThrowable(): void
    {
        $calls = 0;
        $received = null;
        $callback = static function (\Throwable $throwable) use (&$calls, &$received): void {
            ++$calls;
            $received = $throwable;
        };
        $property = new ReflectionProperty(BridgeProcess::class, 'onException');
        $property->setValue($this->process, $callback);
        $onException = new ReflectionMethod(BridgeProcess::class, 'onException');
        $throwable = new \RuntimeException('command failure');

        $onException->invoke($this->process, $throwable);

        self::assertSame(1, $calls);
        self::assertSame($throwable, $received);
    }

    public function testExceptionIsRethrownWithoutCallback(): void
    {
        $property = new ReflectionProperty(BridgeProcess::class, 'onException');
        $property->setValue($this->process, null);
        $onException = new ReflectionMethod(BridgeProcess::class, 'onException');
        $throwable = new \RuntimeException('command failure');

        $this->expectExceptionObject($throwable);

        $onException->invoke($this->process, $throwable);
    }

    public function testExceptionCallbackFailureEscapesAfterSingleInvocation(): void
    {
        $calls = 0;
        $callbackFailure = new \RuntimeException('callback failure');
        $callback = static function () use (&$calls, $callbackFailure): void {
            ++$calls;
            throw $callbackFailure;
        };
        $property = new ReflectionProperty(BridgeProcess::class, 'onException');
        $property->setValue($this->process, $callback);
        $onException = new ReflectionMethod(BridgeProcess::class, 'onException');

        try {
            $onException->invoke($this->process, new \RuntimeException('command failure'));
            self::fail('The callback exception was not rethrown');
        } catch (\RuntimeException $throwable) {
            self::assertSame($callbackFailure, $throwable);
        }

        self::assertSame(1, $calls);
    }

    private function serializeAndRestore(Package $response): Package
    {
        $payload = $this->serializeResponse->invoke($this->process, $response);
        $result = unserialize($payload);

        self::assertInstanceOf(Package::class, $result);
        return $result;
    }
}
