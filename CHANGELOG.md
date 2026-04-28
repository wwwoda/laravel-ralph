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
