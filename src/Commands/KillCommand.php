<?php

namespace Woda\Ralph\Commands;

use Illuminate\Console\Command;
use Woda\Ralph\ScreenManager;
use Woda\Ralph\SessionTracker;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class KillCommand extends Command
{
    protected $signature = 'ralph:kill
        {session? : Session name to kill}
        {--all : Kill all sessions}
        {--force : Skip confirmation}';

    protected $description = 'Kill a ralph session';

    public function handle(SessionTracker $tracker, ScreenManager $screenManager): int
    {
        if ($this->option('all')) {
            return $this->killAll($tracker, $screenManager);
        }

        $sessionArg = $this->argument('session');
        $session = is_string($sessionArg) ? $sessionArg : null;

        if (! $session) {
            $agents = $tracker->all();

            if ($agents === []) {
                $this->components->error('No sessions tracked.');

                return self::FAILURE;
            }

            $session = (string) select(
                label: 'Select session to kill',
                options: array_keys($agents),
            );
        }

        if (! $this->option('force')) {
            if (! confirm("Kill session '{$session}'?")) {
                $this->components->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        $screenManager->kill($session);
        $tracker->untrack($session);

        $this->components->info("Session '{$session}' killed.");

        return self::SUCCESS;
    }

    private function killAll(SessionTracker $tracker, ScreenManager $screenManager): int
    {
        $agents = $tracker->all();

        if ($agents === []) {
            $this->components->info('No sessions to kill.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! confirm('Kill all '.count($agents).' sessions?')) {
                $this->components->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        foreach ($agents as $key => $agent) {
            $agentName = is_string($agent['name'] ?? null) ? $agent['name'] : (string) $key;
            $screenManager->kill($agentName);
            $tracker->untrack((string) $key);
            $this->components->info("Killed '{$key}'.");
        }

        return self::SUCCESS;
    }
}
