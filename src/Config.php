<?php

namespace EasySwoole\Bridge;

class Config
{
    private string $serverName = 'EasySwoole';
    private int $maxPacketLength = 1024 * 1024;
    private bool $locked = false;

    public function getServerName(): string
    {
        return $this->serverName;
    }

    public function setServerName(string $serverName): Config
    {
        $this->assertMutable();
        $serverName = trim($serverName);
        if ($serverName === '' || str_contains($serverName, "\0") || str_contains($serverName, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('serverName must be a non-empty file-name-safe string');
        }
        $this->serverName = $serverName;
        return $this;
    }

    public function getMaxPacketLength(): int
    {
        return $this->maxPacketLength;
    }

    public function setMaxPacketLength(int $maxPacketLength): Config
    {
        $this->assertMutable();
        if ($maxPacketLength <= 4) {
            throw new \InvalidArgumentException('maxPacketLength must be greater than the packet header length');
        }
        $this->maxPacketLength = $maxPacketLength;
        return $this;
    }

    public function lock(): void
    {
        $this->locked = true;
    }

    private function assertMutable(): void
    {
        if ($this->locked) {
            throw new \LogicException('Bridge config cannot be changed after attachServer()');
        }
    }
}
