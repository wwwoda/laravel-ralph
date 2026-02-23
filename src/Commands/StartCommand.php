<?php

namespace Woda\Ralph\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;
use Woda\Ralph\RalphLogger;
use Woda\Ralph\ScreenManager;
use Woda\Ralph\SessionTracker;

use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;

class StartCommand extends Command
{
    protected $signature = 'ralph:start
        {name? : Session name}
        {--issue= : GitHub issue number to work on}
        {--prompt= : Path to prompt file or inline text}
        {--iterations= : Max iterations}
        {--model= : Override Claude model}
        {--budget= : Max USD per Claude invocation}
        {--fresh : Each iteration starts a fresh Claude session}
        {--resume : Resume a previously stopped session}
        {--attach : Attach to screen session after starting}
        {--once : Run single iteration in foreground}';

    protected $description = 'Start a Ralph agent loop';

    public function handle(SessionTracker $tracker, ScreenManager $screenManager): int
    {
        if ($this->option('fresh') && $this->option('resume')) {
            $this->components->error('--fresh and --resume are mutually exclusive.');

            return self::FAILURE;
        }

        if (! $this->validateEnvironment()) {
            return self::FAILURE;
        }

        $this->checkSandboxConfig();

        // Resolve what to work on first (determines suggested name)
        $promptSource = $this->resolvePromptSource();

        // Name: explicit arg > suggested from prompt source > interactive
        $name = $this->resolveName($promptSource['suggested_name']);

        // Write prompt file if needed, or use existing file
        $prompt = $promptSource['file'] ?? $this->writePromptFile($name, $promptSource['content']);

        $sessionId = $this->resolveSessionId($tracker, $name);

        /** @var int $iterations */
        $iterations = $this->option('iterations') ?? config('ralph.loop.default_iterations');
        $iterations = (int) $iterations;

        $workingDir = base_path();
        $model = $this->resolveModel();

        // Create logger and write startup info
        $logger = $this->createSessionLogger($name);
        $logger->info("Session: {$name}");
        $logger->info("Prompt source: {$promptSource['source']}");
        $logger->info("Session ID: {$sessionId}");
        $logger->info("Iterations: {$iterations}");
        $logger->info('Model: '.($model ?? 'default'));
        $logger->info('Mode: '.($this->option('fresh') ? 'fresh' : 'resume'));
        $logger->info("Working dir: {$workingDir}");

        // Build the ralph-loop command
        /** @var string|null $configScriptPath */
        $configScriptPath = config('ralph.script_path');
        $scriptPath = $configScriptPath ?? dirname(__DIR__, 2).'/scripts/ralph-loop.cjs';
        $loopCmd = $this->buildLoopCommand($scriptPath, $prompt, $name, $iterations, $sessionId, $logger->path());
        $logger->debug("Loop command: {$loopCmd}");

        // Foreground (--once) mode
        if ($this->option('once')) {
            $this->components->info("Running single iteration for '{$name}'...");

            $process = Process::path($workingDir)
                ->timeout(0)
                ->env($this->buildEnv());

            if (SymfonyProcess::isTtySupported()) {
                $process = $process->tty();
            }

            $result = $process->run($loopCmd);

            return $result->exitCode() ?? self::FAILURE;
        }

        // Screen session mode
        if ($tracker->isRunning($name)) {
            $this->components->error("Session '{$name}' is already running.");

            return self::FAILURE;
        }

        $this->components->info("Starting ralph session '{$name}'...");

        $envExports = $this->buildEnvExportString();
        $parts = array_filter(['unset CLAUDECODE', $envExports, "cd {$workingDir}", $loopCmd]);
        $screenCmd = implode(' && ', $parts);

        $screenManager->start($name, $screenCmd, $workingDir);

        $tracker->track($name, [
            'name' => $name,
            'prompt_source' => $promptSource['source'],
            'working_path' => $workingDir,
            'session_id' => $sessionId,
            'model' => $model,
            'iterations' => $iterations,
            'screen_name' => $screenManager->fullName($name),
        ]);

        $this->components->info("Session '{$name}' started.");
        $this->components->bulletList([
            "Screen: {$screenManager->fullName($name)}",
            "Working dir: {$workingDir}",
            "Iterations: {$iterations}",
            "Session ID: {$sessionId}",
        ]);

        if ($this->option('attach')) {
            $attachCmd = $screenManager->attachCommand($name);
            $this->components->info('Attaching to screen session...');
            passthru($attachCmd);
        }

        return self::SUCCESS;
    }

    private function resolveName(?string $suggestedName): string
    {
        $name = $this->argument('name');

        if (is_string($name) && $name !== '') {
            return $this->validateName($name);
        }

        if (is_string($suggestedName) && $suggestedName !== '') {
            return $this->validateName($suggestedName);
        }

        $name = text(
            label: 'Session name',
            placeholder: 'feature-name',
            required: true,
            validate: fn (string $value): ?string => preg_match('/^[a-zA-Z0-9-]+$/', $value)
                ? null
                : 'Name must be alphanumeric with hyphens only.',
        );

        return $this->validateName($name);
    }

    private function validateName(string $name): string
    {
        if (! preg_match('/^[a-zA-Z0-9-]+$/', $name)) {
            $this->components->error('Name must be alphanumeric with hyphens only.');
            exit(self::FAILURE);
        }

        return $name;
    }

    private function resolveSessionId(SessionTracker $tracker, string $name): string
    {
        if ($this->option('resume')) {
            $existing = $tracker->get($name);
            $storedId = $existing['session_id'] ?? null;

            if (is_string($storedId) && $storedId !== '') {
                $this->components->info("Resuming session ID: {$storedId}");

                return $storedId;
            }

            $this->components->warn("No stored session ID for '{$name}', starting fresh.");
        }

        return (string) Str::uuid();
    }

    /**
     * @return array{content: string, suggested_name: ?string, source: string, file: ?string}
     */
    private function resolvePromptSource(): array
    {
        // 1. Explicit --issue flag
        $issue = $this->option('issue');
        if (is_string($issue) && $issue !== '') {
            return [
                'content' => $this->fetchIssuePromptContent($issue),
                'suggested_name' => $issue,
                'source' => "issue#{$issue}",
                'file' => null,
            ];
        }

        // 2. Explicit --prompt flag
        $prompt = $this->option('prompt');
        if (is_string($prompt) && $prompt !== '') {
            if (File::exists($prompt)) {
                return [
                    'content' => '',
                    'suggested_name' => null,
                    'source' => $prompt,
                    'file' => $prompt,
                ];
            }

            return [
                'content' => $prompt,
                'suggested_name' => null,
                'source' => 'prompt',
                'file' => null,
            ];
        }

        // 3. Interactive mode
        $interactive = $this->resolveInteractivePromptSource();
        $interactive['file'] = null;

        return $interactive;
    }

    /**
     * @return array{content: string, suggested_name: ?string, source: string}
     */
    private function resolveInteractivePromptSource(): array
    {
        $options = [];

        // Check if gh CLI is available
        $ghAvailable = Process::run('which gh')->successful();
        if ($ghAvailable) {
            $options['issue'] = 'GitHub issue';
        }

        // Check for PRDs
        /** @var string $prdRelPath */
        $prdRelPath = config('ralph.prompt.prd_path');
        $prdPath = base_path($prdRelPath);
        $prds = $this->discoverPrds($prdPath);

        if ($prds !== []) {
            $options['prd'] = 'Select a PRD';
        }

        $options['manual'] = 'Enter prompt manually';

        // If only manual is available, skip the selection
        if (count($options) === 1) {
            return $this->resolveManualPromptSource();
        }

        $source = select(
            label: 'What should the agent work on?',
            options: $options,
        );

        return match ($source) {
            'issue' => $this->resolveInteractiveIssueSource(),
            'prd' => $this->resolveInteractivePrdSource($prdPath, $prds),
            default => $this->resolveManualPromptSource(),
        };
    }

    /**
     * @return array{content: string, suggested_name: string, source: string}
     */
    private function resolveInteractiveIssueSource(): array
    {
        $result = Process::run('gh issue list --state open --limit 100 --json number,title');

        if (! $result->successful()) {
            return $this->resolveManualIssueSource();
        }

        /** @var list<array{number: int, title: string}>|null $issues */
        $issues = json_decode($result->output(), true);

        if (! is_array($issues) || $issues === []) {
            $this->components->warn('No open issues found.');

            return $this->resolveManualIssueSource();
        }

        $options = [];
        foreach ($issues as $issue) {
            $options[$issue['number']] = "#{$issue['number']} {$issue['title']}";
        }

        $issueNumber = search(
            label: 'Search for an issue',
            options: fn (string $value) => array_filter(
                $options,
                fn (string $label) => $value === '' || str_contains(Str::lower($label), Str::lower($value)),
            ),
            placeholder: 'Type to filter...',
            scroll: 10,
        );

        $issueNumber = (string) $issueNumber;

        return [
            'content' => $this->fetchIssuePromptContent($issueNumber),
            'suggested_name' => $issueNumber,
            'source' => "issue#{$issueNumber}",
        ];
    }

    /**
     * @return array{content: string, suggested_name: string, source: string}
     */
    private function resolveManualIssueSource(): array
    {
        $issueNumber = text(
            label: 'Issue number',
            required: true,
            validate: fn (string $value): ?string => preg_match('/^\d+$/', $value)
                ? null
                : 'Must be a number.',
        );

        return [
            'content' => $this->fetchIssuePromptContent($issueNumber),
            'suggested_name' => $issueNumber,
            'source' => "issue#{$issueNumber}",
        ];
    }

    private function fetchIssuePromptContent(string $issueNumber): string
    {
        $this->components->info("Fetching issue #{$issueNumber}...");

        $result = Process::run(
            sprintf('gh issue view %s --json title,body', escapeshellarg($issueNumber)),
        );

        if (! $result->successful()) {
            $this->components->error("Failed to fetch issue #{$issueNumber}: {$result->errorOutput()}");
            exit(self::FAILURE);
        }

        /** @var array<string, mixed>|null $issue */
        $issue = json_decode($result->output(), true);

        if (! is_array($issue) || ! array_key_exists('title', $issue) || ! array_key_exists('body', $issue)
            || ! is_string($issue['title']) || ! is_string($issue['body'])) {
            $this->components->error("Invalid issue data for #{$issueNumber}.");
            exit(self::FAILURE);
        }

        return "# GitHub Issue #{$issueNumber}: {$issue['title']}\n\n{$issue['body']}"
            ."\n\n---\n\nAfter completing each checklist item, update the GitHub issue to check it off."
            ." Fetch the current body with `gh issue view {$issueNumber} --json body -q .body`,"
            .' then `gh issue edit '.$issueNumber." --body '...'` with the checkbox toggled from `- [ ]` to `- [x]`.";
    }

    /**
     * @param  array<string, string>  $prds
     * @return array{content: string, suggested_name: string, source: string}
     */
    private function resolveInteractivePrdSource(string $prdPath, array $prds): array
    {
        $selected = select(
            label: 'Select a PRD',
            options: array_keys($prds),
        );

        $projectMd = $prdPath.'/'.$selected.'/project.md';
        $progressMd = $prdPath.'/'.$selected.'/progress.md';

        $content = "@{$projectMd}";
        if (File::exists($progressMd)) {
            $content .= "\n\n@{$progressMd}";
        }

        return [
            'content' => $content,
            'suggested_name' => (string) $selected,
            'source' => "prd:{$selected}",
        ];
    }

    /**
     * @return array{content: string, suggested_name: null, source: string}
     */
    private function resolveManualPromptSource(): array
    {
        $promptText = textarea(
            label: 'Enter your prompt',
            required: true,
        );

        return [
            'content' => $promptText,
            'suggested_name' => null,
            'source' => 'manual',
        ];
    }

    /**
     * @return array<string, string> Map of PRD name => relative path to project.md
     */
    private function discoverPrds(string $prdPath): array
    {
        if (! File::isDirectory($prdPath)) {
            return [];
        }

        /** @var list<string> $dirs */
        $dirs = File::directories($prdPath);

        return collect($dirs)
            ->mapWithKeys(fn (string $dir): array => [basename($dir) => basename($dir).'/project.md'])
            ->filter(fn (string $file): bool => File::exists($prdPath.'/'.$file))
            ->all();
    }

    private function writePromptFile(string $name, string $content): string
    {
        /** @var string $logDir */
        $logDir = config('ralph.logging.directory');
        $tmpFile = $logDir."/prompt-{$name}.md";
        File::ensureDirectoryExists(dirname($tmpFile));
        File::put($tmpFile, $content);

        return $tmpFile;
    }

    private function resolveModel(): ?string
    {
        $model = $this->option('model');
        if (is_string($model) && $model !== '') {
            return $model;
        }

        /** @var string|null $configModel */
        $configModel = config('ralph.loop.model');

        return is_string($configModel) && $configModel !== '' ? $configModel : null;
    }

    private function buildLoopCommand(string $scriptPath, string $prompt, string $name, int $iterations, string $sessionId, string $logPath): string
    {
        /** @var string $permissionMode */
        $permissionMode = config('ralph.loop.permission_mode');

        $cmd = sprintf(
            'node %s --prompt %s --name %s --iterations %d --permission-mode %s --session-id %s --log-path %s',
            escapeshellarg($scriptPath),
            escapeshellarg($prompt),
            escapeshellarg($name),
            $iterations,
            escapeshellarg($permissionMode),
            escapeshellarg($sessionId),
            escapeshellarg($logPath),
        );

        $model = $this->resolveModel();
        if (is_string($model)) {
            $cmd .= ' --model '.escapeshellarg($model);
        }

        $budget = $this->option('budget');
        if (is_string($budget) && $budget !== '') {
            $cmd .= ' --budget '.escapeshellarg($budget);
        }

        if ($this->option('fresh')) {
            $cmd .= ' --fresh';
        }

        return $cmd;
    }

    /**
     * @return array<string, string>
     */
    private function buildEnv(): array
    {
        /** @var string $suffix */
        $suffix = config('ralph.prompt.suffix', '');
        /** @var string $logDir */
        $logDir = config('ralph.logging.directory', '');
        /** @var string $marker */
        $marker = config('ralph.loop.completion_marker', '');
        /** @var string $continuation */
        $continuation = config('ralph.prompt.continuation', '');
        /** @var int $maxFailures */
        $maxFailures = config('ralph.loop.max_consecutive_failures', 3);
        /** @var int $nonJsonThreshold */
        $nonJsonThreshold = config('ralph.logging.non_json_warn_threshold', 50);

        return array_filter([
            'AGENT_PROMPT_SUFFIX' => $suffix,
            'AGENT_LOG_DIR' => $logDir,
            'AGENT_COMPLETION_MARKER' => $marker,
            'AGENT_CONTINUATION_PROMPT' => $continuation,
            'AGENT_MAX_CONSECUTIVE_FAILURES' => (string) $maxFailures,
            'AGENT_NON_JSON_WARN_THRESHOLD' => (string) $nonJsonThreshold,
        ]);
    }

    private function buildEnvExportString(): string
    {
        $exports = [];
        foreach ($this->buildEnv() as $key => $value) {
            $exports[] = sprintf('export %s=%s', $key, escapeshellarg($value));
        }

        return implode(' && ', $exports);
    }

    private function checkSandboxConfig(): void
    {
        $settingsPath = base_path('.claude/settings.json');

        if (! File::exists($settingsPath)) {
            $this->components->warn('No .claude/settings.json found. Run `php artisan ralph:init` to configure sandbox permissions.');

            return;
        }

        /** @var array<string, mixed>|null $settings */
        $settings = json_decode(File::get($settingsPath), true);

        if (! is_array($settings)) {
            return;
        }

        /** @var array<string, mixed> $sandbox */
        $sandbox = $settings['sandbox'] ?? [];
        $sandboxEnabled = $sandbox['enabled'] ?? false;
        $autoAllow = $sandbox['autoAllowBashIfSandboxed'] ?? false;

        if (! $sandboxEnabled || ! $autoAllow) {
            $this->components->warn('Sandbox not fully configured. Run `php artisan ralph:init` to fix. Without this, Claude may hang waiting for Bash approval.');
        }
    }

    private function createSessionLogger(string $name): RalphLogger
    {
        /** @var string $logDir */
        $logDir = config('ralph.logging.directory');
        $timestamp = now()->format('Y-m-d\TH-i-s');
        $logPath = "{$logDir}/{$name}/{$timestamp}.log";

        return new RalphLogger($logPath);
    }

    private function validateEnvironment(): bool
    {
        $missing = [];

        foreach (['node', 'claude'] as $binary) {
            $result = Process::run("which {$binary}");
            if (! $result->successful()) {
                $missing[] = $binary;
            }
        }

        if (! $this->option('once')) {
            $result = Process::run('which screen');
            if (! $result->successful()) {
                $missing[] = 'screen';
            }
        }

        if ($missing !== []) {
            $this->components->error('Missing required binaries: '.implode(', ', $missing));

            return false;
        }

        return true;
    }
}
