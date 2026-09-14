<?php

namespace Woda\Ralph;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Woda\Ralph\Commands\AttachCommand;
use Woda\Ralph\Commands\InitCommand;
use Woda\Ralph\Commands\KillCommand;
use Woda\Ralph\Commands\LogsCommand;
use Woda\Ralph\Commands\StartCommand;
use Woda\Ralph\Commands\StatusCommand;
use Woda\Ralph\Contracts\CommandRunner;
use Woda\Ralph\Contracts\SessionManager;

class RalphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ralph.php', 'ralph');

        $this->app->singleton(CommandRunner::class, function (): CommandRunner {
            if (! $this->resolveDockerEnabled()) {
                return new NativeCommandRunner;
            }

            /** @var string $service */
            $service = config('ralph.docker.service', 'agent');
            /** @var string $workingDir */
            $workingDir = config('ralph.docker.working_dir', '/var/www/html');

            return new DockerCommandRunner(
                service: $service,
                containerWorkingDir: $workingDir,
                // The compose stack lives in the current worktree (each
                // worktree has its own project name + bind mount), not the
                // main repo. Tracking-file sharing across worktrees is a
                // separate concern handled by SessionTracker below.
                composeProjectPath: base_path(),
            );
        });

        $this->app->singleton(ScreenManager::class, function (Application $app): ScreenManager {
            /** @var string $prefix */
            $prefix = config('ralph.screen.prefix');
            /** @var string $shell */
            $shell = config('ralph.screen.shell');

            return new ScreenManager(
                prefix: $prefix,
                shell: $shell,
                runner: $app->make(CommandRunner::class),
            );
        });

        $this->app->singleton(TmuxManager::class, function (Application $app): TmuxManager {
            /** @var string $prefix */
            $prefix = config('ralph.tmux.prefix');

            return new TmuxManager(
                prefix: $prefix,
                runner: $app->make(CommandRunner::class),
            );
        });

        $this->app->singleton(SessionManager::class, function (Application $app): SessionManager {
            /** @var string $manager */
            $manager = config('ralph.session.manager', 'screen');

            return match ($manager) {
                'screen' => $app->make(ScreenManager::class),
                'tmux' => $app->make(TmuxManager::class),
                default => throw new InvalidArgumentException(
                    "Unknown ralph session manager '{$manager}'. Expected 'screen' or 'tmux'.",
                ),
            };
        });

        $this->app->singleton(SessionTracker::class, function (Application $app): SessionTracker {
            /** @var string $trackingFile */
            $trackingFile = config('ralph.tracking.file');

            return new SessionTracker(
                trackingFile: $this->resolveMainWorktreeRoot().'/'.$trackingFile,
                sessionManager: $app->make(SessionManager::class),
            );
        });
    }

    /**
     * Decide whether docker mode is active. Explicit config wins; absent
     * config auto-detects: we're not inside a container AND base_path()
     * has a docker-compose.yml.
     */
    private function resolveDockerEnabled(): bool
    {
        /** @var bool|null $explicit */
        $explicit = config('ralph.docker.enabled');

        if ($explicit !== null) {
            return (bool) $explicit;
        }

        if (file_exists('/.dockerenv')) {
            return false; // already inside a container
        }

        $composePath = base_path('docker-compose.yml');

        return file_exists($composePath);
    }

    /**
     * Resolve the main worktree root so all worktrees share one tracking file.
     */
    private function resolveMainWorktreeRoot(): string
    {
        try {
            $result = Process::path(base_path())->run('git rev-parse --path-format=absolute --git-common-dir');

            if ($result->successful()) {
                $gitCommonDir = trim($result->output());

                if ($gitCommonDir !== '' && str_starts_with($gitCommonDir, '/')) {
                    return dirname($gitCommonDir);
                }
            }
        } catch (\Throwable) {
            // Not in a git repo or git not available
        }

        return base_path();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ralph.php' => config_path('ralph.php'),
        ], 'ralph-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InitCommand::class,
                StartCommand::class,
                StatusCommand::class,
                AttachCommand::class,
                KillCommand::class,
                LogsCommand::class,
            ]);
        }
    }
}
