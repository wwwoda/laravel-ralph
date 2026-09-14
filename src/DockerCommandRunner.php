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
            'docker compose exec -T%s %s sh -c %s',
            $this->workdirFlag($workingDir),
            escapeshellarg($this->service),
            escapeshellarg($command),
        );

        return $process->run($wrapped);
    }

    public function buildInteractive(string $command): string
    {
        // The user may run this from anywhere on the host, so pin compose to
        // the project dir instead of relying on the caller's cwd.
        $projectFlag = $this->composeProjectPath !== null
            ? ' --project-directory '.escapeshellarg($this->composeProjectPath)
            : '';

        return sprintf(
            'docker compose%s exec -it %s %s',
            $projectFlag,
            escapeshellarg($this->service),
            $command,
        );
    }

    /**
     * `-w <dir>` for `docker compose exec`, or '' when no working dir was
     * requested. Screen (unlike tmux) has no -c flag, so the session's cwd
     * has to come from exec itself.
     */
    private function workdirFlag(?string $workingDir): string
    {
        if ($workingDir === null || $workingDir === '') {
            return '';
        }

        return ' -w '.escapeshellarg($workingDir);
    }

    public function workingDirectory(?string $hostPath = null): ?string
    {
        return $this->containerWorkingDir;
    }

    public function translatePath(string $hostPath): string
    {
        if ($this->composeProjectPath === null) {
            return $hostPath;
        }

        $projectRoot = rtrim($this->composeProjectPath, '/');
        $containerRoot = rtrim($this->containerWorkingDir, '/');

        if ($hostPath === $projectRoot) {
            return $containerRoot;
        }

        $prefix = $projectRoot.'/';
        if (str_starts_with($hostPath, $prefix)) {
            return $containerRoot.'/'.substr($hostPath, strlen($prefix));
        }

        return $hostPath;
    }
}
