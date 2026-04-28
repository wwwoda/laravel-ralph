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

### Changed

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
