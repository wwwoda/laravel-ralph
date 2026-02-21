<?php

namespace Woda\Ralph\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;
use Woda\Ralph\ScreenManager;
use Woda\Ralph\SessionTracker;

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

        $name = $this->resolveName();
        $sessionId = $this->resolveSessionId($tracker, $name);
        $prompt = $this->resolvePrompt($name);

        /** @var int $iterations */
        $iterations = $this->option('iterations') ?? config('ralph.loop.default_iterations');
        $iterations = (int) $iterations;

        $workingDir = base_path();

        // Build the ralph-loop command
        /** @var string|null $configScriptPath */
        $configScriptPath = config('ralph.script_path');
        $scriptPath = $configScriptPath ?? dirname(__DIR__, 2).'/scripts/ralph-loop.js';
        $loopCmd = $this->buildLoopCommand($scriptPath, $prompt, $name, $iterations, $sessionId);

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
        $screenCmd = "{$envExports} cd {$workingDir} && {$loopCmd}";

        $screenManager->start($name, $screenCmd, $workingDir);

        $model = $this->resolveModel();

        $tracker->track($name, [
            'name' => $name,
            'prompt_source' => $this->describePromptSource(),
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

    private function resolveName(): string
    {
        $name = $this->argument('name');

        if (is_string($name) && $name !== '') {
            return $this->validateName($name);
        }

        // Auto-derive from --issue
        $issue = $this->option('issue');
        if (is_string($issue) && $issue !== '') {
            return $this->validateName("issue-{$issue}");
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

    private function resolvePrompt(string $name): string
    {
        // 1. Explicit --issue flag
        $issue = $this->option('issue');
        if (is_string($issue) && $issue !== '') {
            return $this->resolveIssuePrompt($issue, $name);
        }

        // 2. Explicit --prompt flag
        $prompt = $this->option('prompt');
        if (is_string($prompt) && $prompt !== '') {
            return $this->resolveExplicitPrompt($prompt, $name);
        }

        // 3. Interactive mode — two-step drill-down
        return $this->resolveInteractivePrompt($name);
    }

    private function resolveIssuePrompt(string $issueNumber, string $name): string
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

        $promptContent = "# GitHub Issue #{$issueNumber}: {$issue['title']}\n\n{$issue['body']}";

        return $this->writePromptFile($name, $promptContent);
    }

    private function resolveExplicitPrompt(string $prompt, string $name): string
    {
        if (File::exists($prompt)) {
            return $prompt;
        }

        return $this->writePromptFile($name, $prompt);
    }

    private function resolveInteractivePrompt(string $name): string
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
            return $this->resolveManualPrompt($name);
        }

        $source = select(
            label: 'What should the agent work on?',
            options: $options,
        );

        return match ($source) {
            'issue' => $this->resolveInteractiveIssue($name),
            'prd' => $this->resolveInteractivePrd($name, $prdPath, $prds),
            default => $this->resolveManualPrompt($name),
        };
    }

    private function resolveInteractiveIssue(string $name): string
    {
        $issueNumber = text(
            label: 'Issue number',
            required: true,
            validate: fn (string $value): ?string => preg_match('/^\d+$/', $value)
                ? null
                : 'Must be a number.',
        );

        return $this->resolveIssuePrompt($issueNumber, $name);
    }

    /**
     * @param  array<string, string>  $prds
     */
    private function resolveInteractivePrd(string $name, string $prdPath, array $prds): string
    {
        $selected = select(
            label: 'Select a PRD',
            options: array_keys($prds),
        );

        $projectMd = $prdPath.'/'.$selected.'/project.md';
        $progressMd = $prdPath.'/'.$selected.'/progress.md';

        $promptContent = "@{$projectMd}";
        if (File::exists($progressMd)) {
            $promptContent .= "\n\n@{$progressMd}";
        }

        return $this->writePromptFile($name, $promptContent);
    }

    private function resolveManualPrompt(string $name): string
    {
        $promptText = textarea(
            label: 'Enter your prompt',
            required: true,
        );

        return $this->writePromptFile($name, $promptText);
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

    private function buildLoopCommand(string $scriptPath, string $prompt, string $name, int $iterations, string $sessionId): string
    {
        /** @var string $permissionMode */
        $permissionMode = config('ralph.loop.permission_mode');

        $cmd = sprintf(
            'node %s --prompt %s --name %s --iterations %d --permission-mode %s --session-id %s',
            escapeshellarg($scriptPath),
            escapeshellarg($prompt),
            escapeshellarg($name),
            $iterations,
            escapeshellarg($permissionMode),
            escapeshellarg($sessionId),
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

        return array_filter([
            'AGENT_PROMPT_SUFFIX' => $suffix,
            'AGENT_LOG_DIR' => $logDir,
            'AGENT_COMPLETION_MARKER' => $marker,
            'AGENT_CONTINUATION_PROMPT' => $continuation,
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

    private function describePromptSource(): string
    {
        if (is_string($this->option('issue')) && $this->option('issue') !== '') {
            return "issue#{$this->option('issue')}";
        }

        $prompt = $this->option('prompt');

        return is_string($prompt) && $prompt !== '' ? $prompt : 'interactive';
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
