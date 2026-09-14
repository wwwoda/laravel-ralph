<?php

namespace Woda\Ralph;

use RuntimeException;
use Woda\Ralph\Contracts\CommandRunner;
use Woda\Ralph\Contracts\SessionManager;

class TmuxManager implements SessionManager
{
    public function __construct(
        private readonly string $prefix,
        private readonly CommandRunner $runner,
    ) {}

    /**
     * @return list<array{name: string, pid: int, date: string}>
     */
    public function listSessions(): array
    {
        // Format: "<session_created>:<session_name>". `tmux list-sessions` returns
        // exit code 1 when no server is running; treat as empty.
        $result = $this->runner->run(
            'tmux list-sessions -F "#{session_created}:#{session_name}" 2>/dev/null',
        );

        if (! $result->successful()) {
            return [];
        }

        $sessions = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode(':', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$created, $name] = $parts;

            if (! str_starts_with($name, $this->prefix.'-')) {
                continue;
            }

            $sessions[] = [
                'name' => $name,
                'pid' => 0, // tmux sessions don't map 1:1 to a pid
                'date' => date('Y-m-d H:i:s', (int) $created),
            ];
        }

        return $sessions;
    }

    public function isRunning(string $sessionName): bool
    {
        $fullName = $this->fullName($sessionName);

        $result = $this->runner->run(
            sprintf('tmux has-session -t %s 2>/dev/null', escapeshellarg($fullName)),
        );

        return $result->successful();
    }

    public function start(string $sessionName, string $command, ?string $workingDir = null): void
    {
        $fullName = $this->fullName($sessionName);

        if ($this->isRunning($sessionName)) {
            throw new RuntimeException("Tmux session '{$fullName}' is already running.");
        }

        $sessionWorkingDir = $this->runner->workingDirectory($workingDir);

        $parts = [
            'tmux', 'new-session', '-d',
            '-s', escapeshellarg($fullName),
        ];

        if ($sessionWorkingDir !== null && $sessionWorkingDir !== '') {
            $parts[] = '-c';
            $parts[] = escapeshellarg($sessionWorkingDir);
        }

        $parts[] = 'bash';
        $parts[] = '-c';
        $parts[] = escapeshellarg($command);

        $cmd = implode(' ', $parts);

        $result = $this->runner->run($cmd);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to start tmux session: {$result->errorOutput()}");
        }
    }

    public function kill(string $sessionName): bool
    {
        $fullName = $this->fullName($sessionName);

        if (! $this->isRunning($sessionName)) {
            return false;
        }

        $result = $this->runner->run(
            sprintf('tmux kill-session -t %s', escapeshellarg($fullName)),
        );

        return $result->successful();
    }

    public function attachCommand(string $sessionName): string
    {
        return $this->runner->buildInteractive(
            sprintf('tmux attach -t %s', escapeshellarg($this->fullName($sessionName))),
        );
    }

    public function fullName(string $sessionName): string
    {
        return $this->prefix.'-'.$sessionName;
    }
}
