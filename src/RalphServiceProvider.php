<?php

namespace Woda\Ralph;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\ServiceProvider;
use Woda\Ralph\Commands\AttachCommand;
use Woda\Ralph\Commands\InitCommand;
use Woda\Ralph\Commands\KillCommand;
use Woda\Ralph\Commands\LogsCommand;
use Woda\Ralph\Commands\StartCommand;
use Woda\Ralph\Commands\StatusCommand;

class RalphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ralph.php', 'ralph');

        $this->app->singleton(ScreenManager::class, function (): ScreenManager {
            /** @var string $prefix */
            $prefix = config('ralph.screen.prefix');
            /** @var string $shell */
            $shell = config('ralph.screen.shell');

            return new ScreenManager(prefix: $prefix, shell: $shell);
        });

        $this->app->singleton(SessionTracker::class, function (Application $app): SessionTracker {
            /** @var string $trackingFile */
            $trackingFile = config('ralph.tracking.file');

            return new SessionTracker(
                trackingFile: $this->resolveMainWorktreeRoot().'/'.$trackingFile,
                screenManager: $app->make(ScreenManager::class),
            );
        });
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
