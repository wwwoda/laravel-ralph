<?php

namespace Woda\Ralph\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;
use Woda\Ralph\Contracts\SessionManager;
use Woda\Ralph\RalphLogger;
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
        {--attach : Attach to the session after starting}
        {--once : Run single iteration in foreground}
        {--speckit= : Spec Kit feature directory name (e.g. 349-multi-user-orgs). Omit value for interactive selection.}
        {--permission-mode= : Claude permission mode (auto, dontAsk, bypassPermissions, danger, dangerous)}';

    protected $description = 'Start a Ralph agent loop';

    private const VALID_PERMISSION_MODES = ['acceptEdits', 'auto', 'dontAsk', 'bypassPermissions'];

    private const PERMISSION_MODE_ALIASES = [
        'danger' => 'bypassPermissions',
        'dangerous' => 'bypassPermissions',
    ];

    private const SPECKIT_SUFFIX = <<<'SUFFIX'
Read progress.md FIRST if it exists in the spec dir — the `## Codebase Patterns` section at the top captures conventions discovered in earlier iterations. Then read tasks.md and work within ONE phase this iteration.

After implementing:
1. Mark completed tasks `- [x]` in tasks.md
2. Run relevant tests
3. Update progress.md:
   - Curate `## Codebase Patterns` (top of file) with any new conventions. Keep it tight and deduplicated.
   - Append one `## Iteration N — <timestamp>` entry at the bottom with tasks completed, files changed, and learnings.
4. Commit when a logically separate changeset is complete. Floor: one commit per phase or user story (whichever is the smaller unit in this tasks.md). Ceiling: none — commit more when a sub-change is a coherent standalone unit (refactor, generated migration, etc.). No commit on partial phase progress unless the partial piece stands alone.
   - Subject (Pro Git, <=50 chars, imperative): `feat(<feature>): Phase <N> — <title>` or `feat(<feature>): US<N> — <title>`
   - Blank line, then 72-wrap body if needed.
   - Reference: https://git-scm.com/book/en/v2/Distributed-Git-Contributing-to-a-Project
5. If ALL tasks are `- [x]`, output <promise>COMPLETE</promise>
6. Otherwise STOP — the next iteration handles the next phase.
SUFFIX;

    /** @var array<string, mixed> */
    private array $promptSource = [];

    public function handle(SessionTracker $tracker, SessionManager $sessionManager): int
    {
        if ($this->option('fresh') && $this->option('resume')) {
            $this->components->error('--fresh and --resume are mutually exclusive.');

            return self::FAILURE;
        }

        $permissionMode = $this->resolvePermissionMode();
        if ($permissionMode === null) {
            return self::FAILURE;
        }

        if (! $this->validateEnvironment()) {
            return self::FAILURE;
        }

        $this->checkSandboxConfig();

        // Resolve what to work on first (determines suggested name)
        $promptSource = $this->resolvePromptSource();
        $this->promptSource = $promptSource;

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
        $logger->info("Permission mode: {$permissionMode}");
        $logger->info("Working dir: {$workingDir}");

        // Build the ralph-loop command
        /** @var string|null $configScriptPath */
        $configScriptPath = config('ralph.script_path');
        $scriptPath = $configScriptPath ?? dirname(__DIR__, 2).'/scripts/ralph-loop.cjs';
        $loopCmd = $this->buildLoopCommand($scriptPath, $prompt, $name, $iterations, $sessionId, $logger->path(), $permissionMode);
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

        // Detached session mode
        if ($tracker->isRunning($name)) {
            $this->components->error("Session '{$name}' is already running.");

            return self::FAILURE;
        }

        $this->components->info("Starting ralph session '{$name}'...");

        $envExports = $this->buildEnvExportString();
        $parts = array_filter(['unset CLAUDECODE', $envExports, "cd {$workingDir}", $loopCmd]);
        $sessionCmd = implode(' && ', $parts);

        $sessionManager->start($name, $sessionCmd, $workingDir);

        $tracker->track($name, [
            'name' => $name,
            'prompt_source' => $promptSource['source'],
            'working_path' => $workingDir,
            'session_id' => $sessionId,
            'model' => $model,
            'iterations' => $iterations,
            'screen_name' => $sessionManager->fullName($name),
        ]);

        $this->components->info("Session '{$name}' started.");
        $this->components->bulletList([
            "Session: {$sessionManager->fullName($name)}",
            "Working dir: {$workingDir}",
            "Iterations: {$iterations}",
            "Session ID: {$sessionId}",
        ]);

        if ($this->option('attach')) {
            $attachCmd = $sessionManager->attachCommand($name);
            $this->components->info('Attaching to session...');
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
     * @return array{content: string, suggested_name: ?string, source: string, file: ?string, suffix?: string, continuation?: string}
     */
    private function resolvePromptSource(): array
    {
        // 1. Explicit --speckit flag
        $speckit = $this->option('speckit');
        if ($speckit !== null) {
            $source = ! is_string($speckit) || $speckit === ''
                ? $this->resolveInteractiveSpeckitSource()
                : $this->resolveSpeckitSource($speckit);
            $source['file'] = null;

            return $source;
        }

        // 2. Explicit --issue flag
        $issue = $this->option('issue');
        if (is_string($issue) && $issue !== '') {
            return [
                'content' => $this->fetchIssuePromptContent($issue),
                'suggested_name' => $issue,
                'source' => "issue#{$issue}",
                'file' => null,
            ];
        }

        // 3. Explicit --prompt flag
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

        // 4. Interactive mode
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

        // Check for Spec Kit features
        /** @var string $specRelPath */
        $specRelPath = config('ralph.speckit.specs_path');
        $specsPath = base_path($specRelPath);
        $specs = $this->discoverSpecs($specsPath);

        if ($specs !== []) {
            $options['speckit'] = 'Select a Spec Kit feature';
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
            'speckit' => $this->resolveInteractiveSpeckitSource(),
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

    /**
     * @return array{content: string, suggested_name: string, source: string, suffix: string, continuation: string}
     */
    private function resolveSpeckitSource(string $specName): array
    {
        /** @var string $specRelPath */
        $specRelPath = config('ralph.speckit.specs_path');
        $specDir = base_path($specRelPath).'/'.$specName;

        if (! File::exists($specDir.'/tasks.md') || ! File::exists($specDir.'/plan.md')) {
            $this->components->error("Spec '{$specName}' missing required tasks.md or plan.md");
            exit(self::FAILURE);
        }

        return [
            'content' => $this->buildSpeckitPrompt($specDir),
            'suggested_name' => $specName,
            'source' => "speckit:{$specName}",
            'suffix' => self::SPECKIT_SUFFIX,
            'continuation' => $this->buildSpeckitPrompt($specDir, isContinuation: true),
        ];
    }

    /**
     * @return array{content: string, suggested_name: string, source: string, suffix: string, continuation: string}
     */
    private function resolveInteractiveSpeckitSource(): array
    {
        /** @var string $specRelPath */
        $specRelPath = config('ralph.speckit.specs_path');
        $specsPath = base_path($specRelPath);
        $specs = $this->discoverSpecs($specsPath);

        if ($specs === []) {
            $this->components->error('No valid Spec Kit features found.');
            exit(self::FAILURE);
        }

        $selected = select(
            label: 'Select a Spec Kit feature',
            options: array_keys($specs),
        );

        $specDir = $specsPath.'/'.$selected;

        return [
            'content' => $this->buildSpeckitPrompt($specDir),
            'suggested_name' => (string) $selected,
            'source' => "speckit:{$selected}",
            'suffix' => self::SPECKIT_SUFFIX,
            'continuation' => $this->buildSpeckitPrompt($specDir, isContinuation: true),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function discoverSpecs(string $specsPath): array
    {
        if (! File::isDirectory($specsPath)) {
            return [];
        }

        /** @var list<string> $dirs */
        $dirs = File::directories($specsPath);

        return collect($dirs)
            ->mapWithKeys(fn (string $dir): array => [basename($dir) => basename($dir)])
            ->filter(fn (string $name): bool => File::exists($specsPath.'/'.$name.'/tasks.md')
                && File::exists($specsPath.'/'.$name.'/plan.md'))
            ->all();
    }

    private function buildSpeckitPrompt(string $specDir, bool $isContinuation = false): string
    {
        $progressPath = $specDir.'/progress.md';
        $progressExists = File::exists($progressPath);

        $files = [
            $specDir.'/tasks.md',
            $specDir.'/plan.md',
        ];

        if ($progressExists) {
            array_unshift($files, $progressPath);
        }

        foreach (['spec.md', 'data-model.md', 'research.md', 'quickstart.md'] as $optional) {
            $path = $specDir.'/'.$optional;
            if (File::exists($path)) {
                $files[] = $path;
            }
        }

        $contractsDir = $specDir.'/contracts';
        if (File::isDirectory($contractsDir)) {
            foreach (File::files($contractsDir) as $file) {
                if ($file->getExtension() === 'md') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $fileRefs = implode("\n", array_map(fn (string $f): string => "@{$f}", $files));

        $progressNote = $progressExists
            ? 'Read progress.md FIRST — `## Codebase Patterns` (top) captures conventions discovered in earlier iterations. Then read tasks.md.'
            : "progress.md does not yet exist at {$progressPath}. Create it after this iteration with a `## Codebase Patterns` section (top) and one `## Iteration 1 — <timestamp>` entry (bottom).";

        if ($isContinuation) {
            return <<<PROMPT
            Continue implementing the Spec Kit feature. Work within the next incomplete phase.

            {$fileRefs}

            {$progressNote}

            Find the FIRST phase in tasks.md with `- [ ]` tasks. Implement tasks within that phase, mark them `- [x]`, run tests, and update progress.md (curate patterns, append iteration entry). Commit when the phase (or a logically separate changeset within it) completes — Pro Git commit rules: https://git-scm.com/book/en/v2/Distributed-Git-Contributing-to-a-Project. Output <promise>COMPLETE</promise> when zero `- [ ]` tasks remain.
            PROMPT;
        }

        return <<<PROMPT
        Implement the next incomplete phase of the Spec Kit feature.

        {$fileRefs}

        ## Instructions

        1. {$progressNote}
        2. Read tasks.md — find the FIRST phase with `- [ ]` tasks. Work within that ONE phase this iteration.
        3. Read plan.md for architecture, tech stack, and file structure
        4. If available, use spec.md for requirements and acceptance criteria
        5. If available, use data-model.md for entity definitions
        6. If available, use research.md for technical decisions
        7. Implement the tasks in that phase
        8. Run tests relevant to the change
        9. Mark completed tasks `- [x]` in tasks.md
        10. Update progress.md: curate `## Codebase Patterns`, append an iteration entry with tasks done / files changed / learnings
        11. Commit when the phase completes or when a logically separate changeset stands alone. Message: `feat(<feature>): Phase <N> — <title>` (50-char imperative subject, blank line, 72-wrap body). Pro Git rules: https://git-scm.com/book/en/v2/Distributed-Git-Contributing-to-a-Project
        12. If zero `- [ ]` tasks remain in tasks.md, output <promise>COMPLETE</promise>
        PROMPT;
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

    private function resolvePermissionMode(): ?string
    {
        $mode = $this->option('permission-mode');

        if (! is_string($mode) || $mode === '') {
            /** @var string $mode */
            $mode = config('ralph.loop.permission_mode');
        }

        $mode = self::PERMISSION_MODE_ALIASES[$mode] ?? $mode;

        if (! in_array($mode, self::VALID_PERMISSION_MODES, true)) {
            $valid = implode(', ', [
                ...self::VALID_PERMISSION_MODES,
                ...array_keys(self::PERMISSION_MODE_ALIASES),
            ]);
            $this->components->error("Invalid permission mode '{$mode}'. Valid: {$valid}");

            return null;
        }

        return $mode;
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

    private function buildLoopCommand(string $scriptPath, string $prompt, string $name, int $iterations, string $sessionId, string $logPath, string $permissionMode): string
    {
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
        $suffix = $this->promptSource['suffix'] ?? config('ralph.prompt.suffix', '');
        /** @var string $logDir */
        $logDir = config('ralph.logging.directory', '');
        /** @var string $marker */
        $marker = config('ralph.loop.completion_marker', '');
        /** @var string $continuation */
        $continuation = $this->promptSource['continuation'] ?? config('ralph.prompt.continuation', '');
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
        $settings = $this->loadMergedClaudeSettings();

        if ($settings === []) {
            $this->components->warn('No .claude/settings.json found. Run `php artisan ralph:init` to configure sandbox permissions.');

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

    /**
     * Load .claude/settings.json merged with .claude/settings.local.json (local takes precedence).
     *
     * @return array<string, mixed>
     */
    private function loadMergedClaudeSettings(): array
    {
        $settings = [];

        foreach (['settings.json', 'settings.local.json'] as $file) {
            $path = base_path('.claude/'.$file);

            if (! File::exists($path)) {
                continue;
            }

            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode(File::get($path), true);

            if (is_array($decoded)) {
                /** @var array<string, mixed> $settings */
                $settings = array_replace_recursive($settings, $decoded);
            }
        }

        return $settings;
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
            /** @var string $manager */
            $manager = config('ralph.session.manager', 'screen');
            $binary = $manager === 'tmux' ? 'tmux' : 'screen';

            $result = Process::run("which {$binary}");
            if (! $result->successful()) {
                $missing[] = $binary;
            }
        }

        if ($missing !== []) {
            $this->components->error('Missing required binaries: '.implode(', ', $missing));

            return false;
        }

        return true;
    }
}
