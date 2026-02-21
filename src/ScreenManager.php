<?php

namespace Woda\Ralph;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class ScreenManager
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $shell,
    ) {}

    /**
     * @return list<array{name: string, pid: int, date: string}>
     */
    public function listSessions(): array
    {
        // screen -ls returns exit code 1 when sessions exist, 0 when none
        $result = $this->process()->run('screen -ls');
        $output = $result->output();

        $sessions = [];

        preg_match_all('/\t(\d+)\.(\S+)\t\((.+?)\)/', $output, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = $match[2];

            if (! str_starts_with($name, $this->prefix.'-')) {
                continue;
            }

            $sessions[] = [
                'name' => $name,
                'pid' => (int) $match[1],
                'date' => $match[3],
            ];
        }

        return $sessions;
    }

    public function isRunning(string $sessionName): bool
    {
        $fullName = $this->fullName($sessionName);

        foreach ($this->listSessions() as $session) {
            if ($session['name'] === $fullName) {
                return true;
            }
        }

        return false;
    }

    public function start(string $sessionName, string $command, ?string $workingDir = null): void
    {
        $fullName = $this->fullName($sessionName);

        if ($this->isRunning($sessionName)) {
            throw new RuntimeException("Screen session '{$fullName}' is already running.");
        }

        $cmd = sprintf(
            'screen -dmS %s -s %s bash -c %s',
            escapeshellarg($fullName),
            escapeshellarg($this->shell),
            escapeshellarg($command),
        );

        $process = $this->process();

        if ($workingDir) {
            $process = $process->path($workingDir);
        }

        $result = $process->run($cmd);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to start screen session: {$result->errorOutput()}");
        }
    }

    public function kill(string $sessionName): bool
    {
        $fullName = $this->fullName($sessionName);

        if (! $this->isRunning($sessionName)) {
            return false;
        }

        $result = $this->process()->run(
            sprintf('screen -S %s -X quit', escapeshellarg($fullName)),
        );

        return $result->successful();
    }

    /**
     * Returns the command string needed to attach to a session.
     * Actual attachment must happen via passthru/exec since it needs a TTY.
     */
    public function attachCommand(string $sessionName): string
    {
        return sprintf('screen -r %s', escapeshellarg($this->fullName($sessionName)));
    }

    public function fullName(string $sessionName): string
    {
        return $this->prefix.'-'.$sessionName;
    }

    private function process(): PendingProcess
    {
        return Process::timeout(10);
    }
}
