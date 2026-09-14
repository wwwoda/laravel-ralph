<?php

namespace Woda\Ralph\Contracts;

interface SessionManager
{
    /**
     * @return list<array{name: string, pid: int, date: string}>
     */
    public function listSessions(): array;

    public function isRunning(string $sessionName): bool;

    public function start(string $sessionName, string $command, ?string $workingDir = null): void;

    public function kill(string $sessionName): bool;

    /**
     * Returns the command string needed to attach to a session.
     * Actual attachment must happen via passthru/exec since it needs a TTY.
     */
    public function attachCommand(string $sessionName): string;

    public function fullName(string $sessionName): string;
}
