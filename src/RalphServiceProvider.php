<?php

namespace Woda\Ralph;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Woda\Ralph\Commands\AttachCommand;
use Woda\Ralph\Commands\KillCommand;
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
                trackingFile: base_path($trackingFile),
                screenManager: $app->make(ScreenManager::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ralph.php' => config_path('ralph.php'),
        ], 'ralph-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                StartCommand::class,
                StatusCommand::class,
                AttachCommand::class,
                KillCommand::class,
            ]);
        }
    }
}
