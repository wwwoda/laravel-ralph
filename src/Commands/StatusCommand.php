<?php

namespace Woda\Ralph\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Woda\Ralph\ScreenManager;
use Woda\Ralph\SessionTracker;

class StatusCommand extends Command
{
    protected $signature = 'ralph:status
        {--clean : Remove dead entries}
        {--json : Output as JSON}';

    protected $description = 'List running ralph sessions';

    public function handle(SessionTracker $tracker, ScreenManager $screenManager): int
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
            $this->line((string) json_encode($agents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($agents as $key => $agent) {
            $agentName = is_string($agent['name'] ?? null) ? $agent['name'] : (string) $key;
            $running = $screenManager->isRunning($agentName);
            /** @var string $startedAtStr */
            $startedAtStr = $agent['started_at'] ?? 'now';
            $startedAt = CarbonImmutable::parse($startedAtStr);
            $duration = $startedAt->diffForHumans(syntax: CarbonImmutable::DIFF_ABSOLUTE);

            /** @var string $workingPath */
            $workingPath = $agent['working_path'] ?? '-';
            /** @var string $sessionId */
            $sessionId = $agent['session_id'] ?? '-';
            /** @var string $model */
            $model = $agent['model'] ?? 'default';
            /** @var string $screenName */
            $screenName = $agent['screen_name'] ?? '-';

            $rows[] = [
                $key,
                $running ? '<fg=green>running</>' : '<fg=red>stopped</>',
                $workingPath,
                strlen($sessionId) > 8 ? substr($sessionId, 0, 8) : $sessionId,
                $model,
                $duration,
                $screenName,
            ];
        }

        $this->table(
            ['Name', 'Status', 'Path', 'Session', 'Model', 'Duration', 'Screen'],
            $rows,
        );

        return self::SUCCESS;
    }
}
