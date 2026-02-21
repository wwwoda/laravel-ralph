<?php

namespace Woda\Ralph\Commands;

use Illuminate\Console\Command;
use Woda\Ralph\ScreenManager;
use Woda\Ralph\SessionTracker;

use function Laravel\Prompts\select;

class AttachCommand extends Command
{
    protected $signature = 'ralph:attach {session? : Session name to attach to}';

    protected $description = 'Attach to a ralph screen session';

    public function handle(SessionTracker $tracker, ScreenManager $screenManager): int
    {
        $sessionArg = $this->argument('session');
        $session = is_string($sessionArg) ? $sessionArg : null;

        if (! $session) {
            $running = $tracker->running();

            if ($running === []) {
                $this->components->error('No running sessions found.');

                return self::FAILURE;
            }

            if (count($running) === 1) {
                $session = (string) array_key_first($running);
                $this->components->info("Auto-selecting '{$session}'.");
            } else {
                $session = (string) select(
                    label: 'Select session to attach to',
                    options: array_keys($running),
                );
            }
        }

        if (! $screenManager->isRunning($session)) {
            $this->components->error("Session '{$session}' is not running.");

            return self::FAILURE;
        }

        $cmd = $screenManager->attachCommand($session);
        passthru($cmd);

        return self::SUCCESS;
    }
}
