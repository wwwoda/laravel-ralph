# Ralph

Durable Claude Code and Codex agent loops for Laravel. Runs the selected agent CLI iteratively in a detached screen/tmux session (on the host or inside a compose service), persists runtime state, waits out quota limits, and restarts dead sessions from a host watcher.

## Requirements

- PHP 8.3+
- Laravel 12
- Node.js
- [Claude CLI](https://docs.anthropic.com/en/docs/claude-code) (`claude` in PATH) and/or [Codex CLI](https://learn.chatgpt.com/docs/non-interactive-mode) (`codex` in PATH)
- GNU `screen` or `tmux` (not needed with `--once`)

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

# Prompt from file (fails when the file is missing; never falls back to inline text)
php artisan ralph:start --prompt-file=prompts/task.md

# Codex with an explicit model and effort
php artisan ralph:start lane-42 --agent=codex --model=gpt-5.6-terra --effort=medium --prompt-file=prompts/task.md

# Single iteration, foreground (no screen)
php artisan ralph:start --once --prompt="Fix the failing tests"

# Recurring host supervisor: one turn every 20 minutes, bounded permissions
php artisan ralph:start my-supervisor --supervisor --agent=claude --model=opus --effort=medium --prompt-file=/abs/supervisor.md --interval=1200
```

### Options

| Flag | Description |
|------|-------------|
| `name` | Session name (argument, optional) |
| `--issue=N` | Work on a GitHub issue |
| `--prompt=TEXT\|FILE` | Inline prompt or path to prompt file |
| `--prompt-file=FILE` | Prompt file; the command fails if it does not exist |
| `--agent=claude\|codex` | Agent CLI (default `ralph.loop.agent`); fixed for the life of a session |
| `--iterations=N` | Max work attempts (default: 30) |
| `--model=MODEL` | Override agent model (Codex validates it against the live menu) |
| `--effort=LEVEL` | Override effort level (`low`, `medium`, `high`, `xhigh`, `max`; Codex also `ultra`) |
| `--budget=USD` | Max spend per Claude invocation (Claude only) |
| `--fresh` | Don't resume — each work attempt starts a new conversation |
| `--resume` | Re-arm a stopped session with its recorded agent, model, effort and prompt |
| `--supervisor` | Recurring host session: one turn per `--interval` seconds, `auto` permission mode, never inside docker |
| `--interval=N` | Seconds between supervisor turns (default 1200) |
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

# Kill a session (also cancels any pending restart)
php artisan ralph:kill my-feature
php artisan ralph:kill --all --force

# Host recovery watcher (macOS launchd, one tick per minute)
php artisan ralph:watch-install           # install for this checkout
php artisan ralph:watch-install --print   # show the plist without installing
php artisan ralph:watch-install --remove  # remove before deleting the checkout
php artisan ralph:watch                   # one recovery tick (what the watcher runs)

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
    'agent'                    => env('RALPH_AGENT', 'claude'),
    'default_iterations'       => env('RALPH_LOOP_ITERATIONS', 30),
    'permission_mode'          => env('RALPH_PERMISSION_MODE', 'acceptEdits'),
    'model'                    => env('RALPH_MODEL'),
    'effort'                   => env('RALPH_EFFORT'),
    'completion_marker'        => '<promise>COMPLETE</promise>',
    'blocked_marker'           => '<promise>BLOCKED</promise>',
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
3. A Node.js loop runner (`ralph-loop.cjs`) spawns `claude -p --output-format stream-json` or `codex exec --json`, reading the event stream for the session/thread ID, the final message and failure kinds.
4. On the first attempt a new session is created. Later attempts resume that exact session ID (Codex: `codex exec resume <thread>`) with the continuation prompt, unless `--fresh`.
5. The last line of a **successful** final message is compared with the completion marker and the blocked marker. Markers in tool output or in a failed turn never count.
6. The loop stops when: the completion marker is detected (exit 0), the blocked marker is detected (exit 4), work attempts or consecutive failures are exhausted (exit 1), authentication or an unsupported model/effort blocks it (exit 1), or the session is cancelled (exit 3).

All of this runs inside a screen or tmux session so you can detach and reattach freely.

## Agent execution and recovery

Each session keeps `storage/ralph-logs/<name>/state.json` next to its logs. It holds the runtime
status, actual session/thread ID, work attempts, consecutive failures, wait count and the next
attempt time. Writes are atomic and a `.lock` file guarantees one loop process per session.

| Status | Meaning | Watcher restarts it |
| --- | --- | --- |
| `waiting` | eligible for the next attempt | yes |
| `running` | an attempt is in flight (an unowned `running` state means the process died) | yes |
| `waiting_for_quota` | provider usage limit; `next_attempt_at` comes from the reported reset time or bounded backoff | after `next_attempt_at` |
| `waiting_for_network` | connection/stream failure; bounded backoff | after `next_attempt_at` |
| `complete` | completion marker in a successful final message | no |
| `blocked_work` | blocked marker: a human must act, then start a new attempt | no |
| `blocked_auth` | login/credential failure; repair, then `ralph:start <name> --resume` | no |
| `blocked_config` | Codex rejected the model/effort/budget choice | no |
| `failed` | attempts or consecutive failures exhausted | no |
| `cancelled` | `ralph:kill` | no |

Quota and network waits never consume work attempts. Codex re-reads its account rate limits before
retrying so a weekly limit is not mistaken for the short window. The agent is fixed per session:
starting an existing session name with a different `--agent` fails until it is killed.

`ralph:status --json` adds a `runtime` object per session with that state. `ralph:kill` stops the
process, writes `cancelled` and disables restarts. `ralph:start <name> --resume` re-arms a stopped
or auth-blocked session with its recorded settings; finished work needs a new explicit start.

### The host watcher

`ralph:watch` runs one recovery tick: for every tracked session with `auto_restart`, a working
directory that still exists, and a non-terminal state whose `next_attempt_at` has passed, it starts
the recorded launch command through that record's own session manager (host tmux/screen, or
`docker compose exec` in the record's compose project). `ralph:watch-install` registers a launchd
agent that runs the tick every minute from the installing checkout; the plist carries the resolved
directories of `node`, `tmux`, `docker`, `git`, `gh`, `claude` and `codex` so it survives shells that
manage `node` per session. Remove it with `--remove` before deleting that checkout.

Lifecycle logs contain metadata only (status, attempts, event types), never raw tool output.

## License

MIT
