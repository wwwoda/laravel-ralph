<?php

namespace Woda\Ralph;

use Illuminate\Support\Facades\File;

class RalphLogger
{
    public function __construct(
        private readonly string $logPath,
    ) {
        File::ensureDirectoryExists(dirname($this->logPath));
    }

    public function path(): string
    {
        return $this->logPath;
    }

    public function debug(string $message): void
    {
        $this->log('DEBUG', $message);
    }

    public function info(string $message): void
    {
        $this->log('INFO', $message);
    }

    public function warn(string $message): void
    {
        $this->log('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->log('ERROR', $message);
    }

    private function log(string $level, string $message): void
    {
        $timestamp = now()->toIso8601String();
        File::append($this->logPath, "[{$timestamp}] [{$level}] {$message}\n");
    }
}
