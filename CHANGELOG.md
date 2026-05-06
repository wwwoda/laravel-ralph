# Changelog

## Unreleased

### Added

- **Tmux support for detached sessions.** A new `SessionManager` contract
  (`Woda\Ralph\Contracts\SessionManager`) abstracts session-manager concerns.
  `ScreenManager` (existing, default) and the new `TmuxManager` both implement
  it. Selection is controlled by `config('ralph.session.manager')` /
  `RALPH_SESSION_MANAGER` (`screen` | `tmux`; default `screen` — fully
  backward-compatible). `validateEnvironment()` checks the selected binary.
  Every ralph command (`start`, `attach`, `kill`, `status`) now injects the
  interface rather than the concrete screen class.
- **TmuxManager** — creates standalone detached tmux sessions per ralph
  (`tmux new-session -d -s ralph-<name> …`), mirrors the screen-manager
  interface, exposes `tmux attach -t …` as the attach command.
- **Docker mode for Sail / compose-based projects.** A new
  `Woda\Ralph\Contracts\CommandRunner` abstracts where shell commands run.
  `NativeCommandRunner` runs them on the current host; `DockerCommandRunner`
  wraps every invocation with `docker compose exec -T <service> sh -c …`,
  so SessionManager (and anything else using a `CommandRunner`) operates
  inside the configured compose service. `TmuxManager` and `ScreenManager`
  now inject a `CommandRunner` rather than calling `Process` directly.
  Selection: `config('ralph.docker.enabled')` — `null` auto-detects (on
  when `/.dockerenv` is absent AND `base_path()/docker-compose.yml` exists),
  `true` forces on, `false` forces off. Configurable knobs:
  `ralph.docker.service` (default `agent`),
  `ralph.docker.working_dir` (default `/var/www/html`).
  Use case: claude `--dangerously-skip-permissions` is sandboxed to the
  `agent` container; `php artisan ralph:start` from the host transparently
  spawns the loop inside the container; `attachCommand` returns
  `docker compose exec -it agent tmux attach -t …`.
- **`StartCommand::validateEnvironment()` is docker-aware.** In docker
  mode the host only needs `docker`; node/claude/screen/tmux live in the
  container. The compose service is also asserted as running.

### Fixed

- **Docker mode: detached sessions died immediately because the loop
  command was built with host paths.** `StartCommand` baked
  `cd <hostPath>` plus host-path `node <scriptPath>`, `--prompt
  <hostPath>`, and `--log-path <hostPath>` into the shell command run
  by the SessionManager — under docker mode that command runs inside
  the agent service via `docker compose exec`, where host paths like
  `/Volumes/...` do not exist. The session would start, bash would
  fail at `cd`, and the tmux/screen session would exit before the loop
  ever spawned. Fixed by adding `CommandRunner::translatePath()` that
  rewrites paths under the compose project root to the container's
  bind-mount target (`/var/www/html` by default); `StartCommand` now
  translates `workingDir`, `scriptPath`, `prompt`, `logPath`, and
  `AGENT_LOG_DIR` for session mode. `--once` (foreground host mode)
  is unaffected.
- **Docker mode: `composeProjectPath` pointed at the main worktree
  root, not the current worktree.** In a sail-style multi-worktree
  setup each worktree has its own compose project (different name,
  different bind mount), so `docker compose exec` must run from the
  worktree's directory. The provider was using
  `resolveMainWorktreeRoot()` (correct for sharing a tracking file
  across worktrees, wrong for compose targeting). Switched the docker
  runner to `base_path()` while keeping the tracking-file lookup
  unchanged.

### Changed

- **`Contracts\CommandRunner` adds `translatePath(string): string`.**
  `NativeCommandRunner` returns the path unchanged; `DockerCommandRunner`
  rewrites paths under `composeProjectPath` to `containerWorkingDir`.
  Implementers outside the package must add this method.

- **Speckit mode prompt**: iterates over a phase rather than a single task. The
  first iteration's instruction block now tells the agent to work within the
  next incomplete phase from `tasks.md`, to curate a per-spec `progress.md`
  (top-of-file `## Codebase Patterns` for carry-forward conventions; per-iteration
  entries appended below), and to commit per logically separate changeset with a
  floor of one commit per phase or user story — whichever is the smaller unit —
  following Pro Git's commit guidelines.
- **Speckit mode file refs**: `progress.md` is now included in the iteration
  prompt's `@`-reference list when it exists; when absent the agent is
  instructed to create it.
- **`SessionTracker` ctor** now takes `SessionManager $sessionManager` instead
  of `ScreenManager $screenManager`. Binding remains automatic via the service
  container; consumers who injected `ScreenManager` directly should switch to
  the `SessionManager` interface (`ScreenManager` still implements it).
- **`StatusCommand` table header**: the last column is now labelled `Session`
  (was `Screen`); the session-ID column is abbreviated to `SID` to make room.

## v0.1.0

Initial release.
