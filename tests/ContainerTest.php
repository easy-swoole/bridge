<?php

declare(strict_types=1);

namespace EasySwoole\Bridge\Tests;

use EasySwoole\Bridge\CommandInterface;
use EasySwoole\Bridge\Container;
use EasySwoole\Bridge\Package;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function testCommandNamesAreNormalized(): void
    {
        $command = $this->command('  PiNg  ');
        $container = new Container();
        $container->set($command);

        self::assertSame($command, $container->get(' ping '));
        self::assertSame($command, $container->get('PING'));
    }

    public function testEmptyCommandNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('command name must not be empty');

        (new Container())->set($this->command('   '));
    }

    public function testExistingCommandIsOnlyReplacedWhenCoverIsTrue(): void
    {
        $first = $this->command('ping');
        $second = $this->command('PING');
        $container = new Container();

        $container->set($first);
        $container->set($second);
        self::assertSame($first, $container->get('ping'));

        $container->set($second, true);
        self::assertSame($second, $container->get('ping'));
    }

    private function command(string $name): CommandInterface
    {
        return new class($name) implements CommandInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function commandName(): string
            {
                return $this->name;
            }

            public function exec(Package $request, Package $responsePackage): void
            {
            }
        };
    }
}
