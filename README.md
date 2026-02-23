# Ralph

Claude Code agent loop runner for Laravel. Runs Claude CLI iteratively in a GNU screen session, tracking progress, detecting completion, and logging everything.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Node.js
- [Claude CLI](https://docs.anthropic.com/en/docs/claude-code) (`claude` in PATH)
- GNU `screen` (not needed with `--once`)

## Installation

```bash
composer require woda/laravel-ralph
```

## Setup

```bash
php artisan ralph:init
```

Configures `.claude/settings.json` with sandbox permissions so Claude doesn't hang waiting for bash approval.

## Usage

```bash
# Interactive — choose from open GitHub issues, PRDs, or enter a prompt manually
php artisan ralph:start

# Work on a GitHub issue
php artisan ralph:start --issue=42

# Inline prompt
php artisan ralph:start --prompt="Refactor the payment service"

# Prompt from file
php artisan ralph:start --prompt=prompts/task.md

# Single iteration, foreground (no screen)
php artisan ralph:start --once --prompt="Fix the failing tests"
```

### Options

| Flag | Description |
|------|-------------|
| `name` | Session name (argument, optional) |
| `--issue=N` | Work on a GitHub issue |
| `--prompt=TEXT\|FILE` | Inline prompt or path to prompt file |
| `--iterations=N` | Max iterations (default: 30) |
| `--model=MODEL` | Override Claude model |
| `--budget=USD` | Max spend per Claude invocation |
| `--fresh` | Don't resume — each iteration is independent |
| `--resume` | Resume a previous session |
| `--attach` | Auto-attach to screen after start |
| `--once` | Single iteration in foreground |

### Managing Sessions

```bash
# List running sessions
php artisan ralph:status
php artisan ralph:status --json
php artisan ralph:status --clean    # Remove dead entries

# Attach to a session
php artisan ralph:attach
php artisan ralph:attach my-feature

# Kill a session
php artisan ralph:kill my-feature
php artisan ralph:kill --all --force

# View logs
php artisan ralph:logs
php artisan ralph:logs --tail
php artisan ralph:logs --lines=100
php artisan ralph:logs --all        # List all log files
```

## Configuration

```bash
php artisan vendor:publish --tag=ralph-config
```

### `config/ralph.php`

```php
'loop' => [
    'default_iterations'       => env('RALPH_LOOP_ITERATIONS', 30),
    'permission_mode'          => env('RALPH_PERMISSION_MODE', 'acceptEdits'),
    'model'                    => env('RALPH_MODEL'),
    'completion_marker'        => '<promise>COMPLETE</promise>',
    'max_consecutive_failures' => env('RALPH_MAX_CONSECUTIVE_FAILURES', 3),
],

'prompt' => [
    'default_file'  => env('RALPH_PROMPT_FILE'),
    'prd_path'      => 'prd/backlog',
    'suffix'        => 'Focus on one task at a time. Run tests after changes. ...',
    'continuation'  => 'Continue working. Check the PRD and progress files ...',
],

'screen' => [
    'prefix' => 'ralph',
    'shell'  => env('RALPH_SCREEN_SHELL', 'zsh'),
],

'tracking' => [
    'file' => env('RALPH_TRACKING_FILE', '.live-agents'),
],

'logging' => [
    'directory'               => storage_path('ralph-logs'),
    'non_json_warn_threshold' => 50,
],

'script_path' => null,
```

### Prompt Customization

Ralph appends `prompt.suffix` to every initial prompt and uses `prompt.continuation` as the prompt for iterations 2+. There is no base system prompt — Ralph passes your content directly to `claude -p` and relies on Claude's defaults and your project's `CLAUDE.md`.

### PRD Mode

Place PRDs at `prd/backlog/{name}/project.md`. Optionally add a `progress.md` alongside it. Ralph discovers these in the interactive menu and passes them as `@file` references to Claude.

## How It Works

1. You provide a prompt (issue, PRD, file, or text).
2. Ralph writes the prompt to a temp file, appends the configured suffix.
3. A Node.js loop runner (`ralph-loop.cjs`) spawns `claude -p` with `--output-format stream-json`.
4. On iteration 1, a new session is created. On iterations 2+, the session is resumed with the continuation prompt.
5. Each iteration's output is scanned for the completion marker (`<promise>COMPLETE</promise>`).
6. The loop stops when: the marker is detected (exit 0), max iterations reached (exit 2), or consecutive failures exceed the threshold (exit 1).

All of this runs inside a GNU screen session so you can detach and reattach freely.

## License

MIT
