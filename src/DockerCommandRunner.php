<?php

namespace Woda\Ralph;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Woda\Ralph\Contracts\CommandRunner;

/**
 * Wraps every shell invocation with `docker compose exec` so that
 * SessionManager (and anything else using a CommandRunner) operates
 * inside the configured compose service.
 *
 * Selected by RalphServiceProvider when `config('ralph.docker.enabled')`
 * is true (or when auto-detection finds /.dockerenv missing AND a
 * docker-compose.yml in base_path()).
 */
class DockerCommandRunner implements CommandRunner
{
    public function __construct(
        private readonly string $service = 'agent',
        private readonly string $containerWorkingDir = '/var/www/html',
        private readonly ?string $composeProjectPath = null,
    ) {}

    public function run(string $command, ?string $workingDir = null, int $timeout = 10): ProcessResult
    {
        $process = Process::timeout($timeout);

        // `docker compose exec` runs from the project dir (where compose.yml lives).
        if ($this->composeProjectPath !== null) {
            $process = $process->path($this->composeProjectPath);
        }

        $wrapped = sprintf(
            'docker compose exec -T %s sh -c %s',
            escapeshellarg($this->service),
            escapeshellarg($command),
        );

        return $process->run($wrapped);
    }

    public function buildInteractive(string $command): string
    {
        return sprintf(
            'docker compose exec -it %s %s',
            escapeshellarg($this->service),
            $command,
        );
    }

    public function workingDirectory(?string $hostPath = null): ?string
    {
        return $this->containerWorkingDir;
    }
}
