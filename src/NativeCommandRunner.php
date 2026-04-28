<?php

namespace Woda\Ralph;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Woda\Ralph\Contracts\CommandRunner;

class NativeCommandRunner implements CommandRunner
{
    public function run(string $command, ?string $workingDir = null, int $timeout = 10): ProcessResult
    {
        $process = Process::timeout($timeout);

        if ($workingDir !== null && $workingDir !== '') {
            $process = $process->path($workingDir);
        }

        return $process->run($command);
    }

    public function buildInteractive(string $command): string
    {
        return $command;
    }

    public function workingDirectory(?string $hostPath = null): ?string
    {
        return $hostPath;
    }
}
