<?php

declare(strict_types=1);

namespace EasySwoole\Bridge\Tests;

use EasySwoole\Bridge\Package;
use EasySwoole\Bridge\StatusEnum;
use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase
{
    public function testDefaultsAreSafeToRead(): void
    {
        $package = new Package();

        self::assertSame('', $package->getCommand());
        self::assertSame(StatusEnum::UN_INIT, $package->getStatus());
        self::assertNull($package->getArgs());
        self::assertNull($package->getMsg());
        self::assertNull($package->getClientSocket());
    }

    public function testCommandIsTrimmed(): void
    {
        $package = new Package();
        $package->setCommand('  ping  ');

        self::assertSame('ping', $package->getCommand());
    }

    public function testEmptyCommandIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('command must not be empty');

        (new Package())->setCommand(" \t\n ");
    }
}
