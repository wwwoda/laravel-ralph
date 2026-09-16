<?php

namespace Woda\Ralph;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use Woda\Ralph\Contracts\SessionManager;

class SessionTracker
{
    /** Runtime statuses that end a run; the watcher never restarts them. */
    public const array TERMINAL_STATUSES = ['complete', 'failed', 'blocked_auth', 'blocked_config', 'blocked_work', 'cancelled'];

    public function __construct(
        private readonly string $trackingFile,
        private readonly SessionManager $sessionManager,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        /** @var array<string, array<string, mixed>> */
        return $this->read();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function running(): array
    {
        $agents = $this->all();

        return array_filter($agents, fn (array $agent): bool => isset($agent['name']) && is_string($agent['name']) && $this->managerFor($agent)->isRunning($agent['name']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function track(string $key, array $data): void
    {
        $this->withLock(function () use ($key, $data): void {
            $agents = $this->read();
            $agents[$key] = [
                ...$data,
                'started_at' => CarbonImmutable::now()->toIso8601String(),
            ];
            $this->write($agents);
        });
    }

    public function untrack(string $key): void
    {
        $this->withLock(function () use ($key): void {
            $agents = $this->read();
            unset($agents[$key]);
            $this->write($agents);
        });
    }

    /**
     * Remove entries whose sessions are no longer running.
     *
     * @return list<string> Keys that were cleaned
     */
    public function clean(): array
    {
        $cleaned = [];

        $this->withLock(function () use (&$cleaned): void {
            $agents = $this->read();

            foreach ($agents as $key => $agent) {
                if (! empty($agent['auto_restart']) && ! $this->isFinished($agent)) {
                    continue;
                }
                $agentName = isset($agent['name']) && is_string($agent['name']) ? $agent['name'] : null;
                if ($agentName === null || ! $this->managerFor($agent)->isRunning($agentName)) {
                    unset($agents[$key]);
                    $cleaned[] = (string) $key;
                }
            }

            $this->write($agents);
        });

        return $cleaned;
    }

    public function isRunning(string $key): bool
    {
        $agents = $this->all();

        if (! isset($agents[$key])) {
            return false;
        }

        $name = $agents[$key]['name'] ?? null;

        return is_string($name) && $this->managerFor($agents[$key])->isRunning($name);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /** @param array<string, mixed> $record */
    public function start(string $name, array $record): void
    {
        $this->withLock(function () use ($name, $record): void {
            $records = $this->read();
            $existing = $records[$name] ?? [];
            if ($existing !== [] && (! empty($existing['auto_restart']) && ! $this->isFinished($existing)
                || $this->managerFor($existing)->isRunning($name))) {
                throw new RuntimeException('Session is active or waiting. Use --resume or cancel it first.');
            }
            // One agent per run: a record keeps its provider until it is untracked.
            $existingAgent = $existing['agent'] ?? null;
            if (is_string($existingAgent) && isset($record['agent']) && $existingAgent !== $record['agent']) {
                throw new RuntimeException("Session '{$name}' was started with agent '{$existingAgent}'. Kill it before switching agents.");
            }
            $statePath = $record['state_path'] ?? null;
            $command = $record['launch_command'] ?? null;
            $path = $record['working_path'] ?? null;
            if (! is_string($statePath) || ! is_string($command) || ! is_string($path)) {
                throw new RuntimeException('Session launch record is incomplete.');
            }
            File::ensureDirectoryExists(dirname($statePath));
            File::delete($statePath.'.cancel');
            File::replace($statePath, json_encode(['status' => 'waiting', 'attempts' => 0, 'failures' => 0,
                'session_id' => null, 'next_attempt_at' => null], JSON_THROW_ON_ERROR));
            $records[$name] = [...$record, 'started_at' => CarbonImmutable::now()->toIso8601String()];
            $this->write($records);
            $this->managerFor($record)->start($name, $command, $path);
        });
    }

    public function resume(string $name): void
    {
        $this->withLock(function () use ($name): void {
            $records = $this->read();
            $record = $records[$name] ?? null;
            if ($record === null || ! is_string($record['launch_command'] ?? null)) {
                throw new RuntimeException('Session predates durable recovery. Start it with its original prompt.');
            }
            $state = $this->state($record);
            $status = $state['status'] ?? null;
            // Only auth blocks and cancellations are resumable as-is; finished work needs a new explicit attempt.
            if (in_array($status, ['complete', 'failed', 'blocked_config', 'blocked_work'], true)) {
                throw new RuntimeException("Session ended with status '{$status}'. Start a new attempt with an explicit prompt.");
            }
            $records[$name]['auto_restart'] = true;
            $statePath = $record['state_path'] ?? null;
            if (is_string($statePath)) {
                File::delete($statePath.'.cancel');
                if (in_array($status, ['blocked_auth', 'cancelled'], true)) {
                    $state['status'] = 'waiting';
                    File::replace($statePath, json_encode($state, JSON_THROW_ON_ERROR));
                }
            }
            $this->write($records);
        });
        $this->recover();
    }

    /** @param array<string, mixed> $record */
    public function isFinished(array $record): bool
    {
        return in_array($this->state($record)['status'] ?? null, self::TERMINAL_STATUSES, true);
    }

    /** @param array<string, mixed> $record */
    public function managerFor(array $record): SessionManager
    {
        return array_key_exists('docker', $record)
            ? (new SessionRouter)->forRecord($record) : $this->sessionManager;
    }

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function state(array $record): array
    {
        $path = $record['state_path'] ?? null;
        if (! is_string($path) || ! File::isFile($path)) {
            return [];
        }
        /** @var array<string, mixed> $state */
        $state = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        return $state;
    }

    public function cancel(string $key): void
    {
        $this->withLock(function () use ($key): void {
            $records = $this->read();
            $record = $records[$key] ?? null;
            if ($record === null) {
                return;
            }
            $records[$key]['auto_restart'] = false;
            $this->write($records);
            $statePath = $record['state_path'] ?? null;
            if (is_string($statePath) && is_dir(dirname($statePath))) {
                File::put($statePath.'.cancel', 'cancelled');
                File::replace($statePath, json_encode([...$this->state($record), 'status' => 'cancelled', 'next_attempt_at' => null], JSON_THROW_ON_ERROR));
            }
            $name = $record['name'] ?? $key;
            if (is_string($name)) {
                $this->managerFor($record)->kill($name);
            }
        });
    }

    public function recover(): void
    {
        $this->withLock(function (): void {
            foreach ($this->read() as $record) {
                $path = $record['working_path'] ?? null;
                $command = $record['launch_command'] ?? null;
                $name = $record['name'] ?? null;
                if (empty($record['auto_restart']) || ! is_string($path) || ! is_dir($path)
                    || ! is_string($command) || ! is_string($name)) {
                    continue;
                }
                try {
                    $state = $this->state($record);
                } catch (Throwable) {
                    continue;
                }
                if (in_array($state['status'] ?? null, self::TERMINAL_STATUSES, true)
                    || (is_numeric($state['next_attempt_at'] ?? null) && $state['next_attempt_at'] > microtime(true) * 1000)) {
                    continue;
                }
                $manager = $this->managerFor($record);
                if (! $manager->isRunning($name)) {
                    try {
                        $manager->start($name, $command, $path);
                    } catch (RuntimeException) {
                        // Docker may be stopped. Other sessions can still recover.
                        continue;
                    }
                }
            }
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function read(): array
    {
        if (! File::exists($this->trackingFile)) {
            return [];
        }

        $content = File::get($this->trackingFile);

        $data = json_decode($content, true);

        /** @var array<string, array<string, mixed>> */
        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(array $data): void
    {
        File::replace(
            $this->trackingFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    private function withLock(callable $callback): void
    {
        $lockFile = $this->trackingFile.'.lock';
        $handle = fopen($lockFile, 'c');

        if ($handle === false) {
            throw new RuntimeException("Cannot open lock file: {$lockFile}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire lock on tracking file.');
            }

            $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
