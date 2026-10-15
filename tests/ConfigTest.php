<?php

declare(strict_types=1);

namespace EasySwoole\Bridge\Tests;

use EasySwoole\Bridge\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new Config();

        self::assertSame('EasySwoole', $config->getServerName());
        self::assertSame(1024 * 1024, $config->getMaxPacketLength());
    }

    public function testSettersAreFluentAndNormalizeValues(): void
    {
        $config = new Config();

        self::assertSame($config, $config->setServerName('  Worker  '));
        self::assertSame($config, $config->setMaxPacketLength(2 * 1024 * 1024));
        self::assertSame('Worker', $config->getServerName());
        self::assertSame(2 * 1024 * 1024, $config->getMaxPacketLength());
    }

    public function testLockedConfigCannotBeChanged(): void
    {
        $config = new Config();
        $config->lock();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Bridge config cannot be changed after attachServer()');

        $config->setServerName('Worker');
    }
}
