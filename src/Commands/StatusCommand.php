<?php

namespace Woda\Ralph\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Woda\Ralph\Contracts\SessionManager;
use Woda\Ralph\SessionTracker;

class StatusCommand extends Command
{
    protected $signature = 'ralph:status
        {--clean : Remove dead entries}
        {--json : Output as JSON}';

    protected $description = 'List running ralph sessions';

    public function handle(SessionTracker $tracker, SessionManager $sessionManager): int
    {
        if ($this->option('clean')) {
            $cleaned = $tracker->clean();
            if ($cleaned !== []) {
                $this->components->info('Cleaned '.count($cleaned).' dead entries: '.implode(', ', $cleaned));
            } else {
                $this->components->info('No dead entries found.');
            }
        }

        $agents = $tracker->all();

        if ($agents === []) {
            $this->components->info('No sessions tracked.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            foreach ($agents as &$record) {
                $record['runtime'] = $tracker->state($record);
                unset($record['launch_command']);
            }
            unset($record);
            $this->line((string) json_encode($agents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($agents as $key => $agent) {
            $agentName = is_string($agent['name'] ?? null) ? $agent['name'] : (string) $key;
            $running = $tracker->managerFor($agent)->isRunning($agentName);
            $state = $tracker->state($agent);
            /** @var string $startedAtStr */
            $startedAtStr = $agent['started_at'] ?? 'now';
            $startedAt = CarbonImmutable::parse($startedAtStr);
            $duration = $startedAt->diffForHumans(syntax: CarbonImmutable::DIFF_ABSOLUTE);

            /** @var string $workingPath */
            $workingPath = $agent['working_path'] ?? '-';
            $sessionId = $state['session_id'] ?? $agent['session_id'] ?? '-';
            $sessionId = is_string($sessionId) ? $sessionId : '-';
            /** @var string $model */
            $model = $agent['model'] ?? 'default';
            /** @var string $effort */
            $effort = $agent['effort'] ?? 'default';
            /** @var string $screenName */
            $screenName = $agent['screen_name'] ?? '-';

            $rows[] = [
                $key,
                ! $running && ($state['status'] ?? null) === 'running' ? 'interrupted' : ($state['status'] ?? ($running ? 'running' : 'stopped')),
                $agent['agent'] ?? 'claude',
                $workingPath,
                strlen($sessionId) > 8 ? substr($sessionId, 0, 8) : $sessionId,
                $model,
                $effort,
                $duration,
                $screenName,
            ];
        }

        $this->table(
            ['Name', 'Status', 'Agent', 'Path', 'SID', 'Model', 'Effort', 'Duration', 'Session'],
            $rows,
        );

        return self::SUCCESS;
    }
}
