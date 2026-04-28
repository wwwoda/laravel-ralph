<?php

namespace Woda\Ralph\Contracts;

use Illuminate\Contracts\Process\ProcessResult;

/**
 * Abstracts where shell commands run.
 *
 * NativeCommandRunner runs them directly on the current host.
 * DockerCommandRunner wraps every invocation with `docker compose exec`,
 * so the SessionManager (and anything else using it) operates inside
 * the configured Sail/compose service. Selected by RalphServiceProvider
 * from `config('ralph.docker.enabled')` (auto-detected via /.dockerenv
 * + presence of docker-compose.yml at base_path() when null).
 */
interface CommandRunner
{
    /**
     * Run a shell command synchronously and return the result.
     */
    public function run(string $command, ?string $workingDir = null, int $timeout = 10): ProcessResult;

    /**
     * Build the user-facing command string for an INTERACTIVE attach
     * (e.g. `tmux attach`). Returns the input prefixed with `docker compose
     * exec -it <service>` in docker mode, unchanged otherwise.
     *
     * The user copy-pastes this into their terminal to attach to the session.
     */
    public function buildInteractive(string $command): string;

    /**
     * The working directory to pass to detached sessions in this runner's
     * environment. Native: returns $hostPath as-is. Docker: returns the
     * container-side path (defaults to /var/www/html).
     */
    public function workingDirectory(?string $hostPath = null): ?string;
}
