<?php

namespace Woda\Ralph\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

use function Laravel\Prompts\select;

class LogsCommand extends Command
{
    protected $signature = 'ralph:logs
        {session? : Session name to view logs for}
        {--tail : Follow the most recent log file}
        {--lines=50 : Number of lines to show}
        {--all : Show all log files with sizes and dates}';

    protected $description = 'View Ralph session logs';

    public function handle(): int
    {
        /** @var string $logDir */
        $logDir = config('ralph.logging.directory');

        if (! File::isDirectory($logDir)) {
            $this->components->warn('No log directory found.');

            return self::SUCCESS;
        }

        $session = $this->argument('session');

        if (! is_string($session) || $session === '') {
            $session = $this->selectSession($logDir);

            if ($session === null) {
                return self::SUCCESS;
            }
        }

        $sessionDir = $logDir.'/'.$session;

        if (! File::isDirectory($sessionDir)) {
            $this->components->error("No logs found for session '{$session}'.");

            return self::FAILURE;
        }

        if ($this->option('all')) {
            return $this->showAllFiles($sessionDir, $session);
        }

        $latestLog = $this->findLatestLog($sessionDir);

        if ($latestLog === null) {
            $this->components->warn("No log files found for session '{$session}'.");

            return self::SUCCESS;
        }

        if ($this->option('tail')) {
            $this->components->info("Tailing: {$latestLog}");
            passthru('tail -f '.escapeshellarg($latestLog));

            return self::SUCCESS;
        }

        /** @var int $lines */
        $lines = (int) $this->option('lines');
        $this->components->info("Last {$lines} lines of: {$latestLog}");
        passthru(sprintf('tail -n %d %s', $lines, escapeshellarg($latestLog)));

        return self::SUCCESS;
    }

    private function selectSession(string $logDir): ?string
    {
        /** @var list<string> $dirs */
        $dirs = File::directories($logDir);

        if ($dirs === []) {
            $this->components->warn('No session logs found.');

            return null;
        }

        // Sort by most recently modified
        usort($dirs, fn (string $a, string $b): int => File::lastModified($b) <=> File::lastModified($a));

        $options = [];
        foreach ($dirs as $dir) {
            $name = basename($dir);
            $logCount = count(File::files($dir));
            $options[$name] = "{$name} ({$logCount} log files)";
        }

        /** @var string $selected */
        $selected = select(
            label: 'Select a session',
            options: $options,
        );

        return $selected;
    }

    private function showAllFiles(string $sessionDir, string $session): int
    {
        $finder = Finder::create()
            ->files()
            ->name('*.log')
            ->in($sessionDir)
            ->sortByModifiedTime()
            ->reverseSorting();

        if (! $finder->hasResults()) {
            $this->components->warn("No log files found for session '{$session}'.");

            return self::SUCCESS;
        }

        $rows = [];
        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            $rows[] = [
                $file->getFilename(),
                $this->formatSize($file->getSize()),
                date('Y-m-d H:i:s', $file->getMTime()),
            ];
        }

        $this->table(['File', 'Size', 'Modified'], $rows);

        return self::SUCCESS;
    }

    private function findLatestLog(string $sessionDir): ?string
    {
        $finder = Finder::create()
            ->files()
            ->name('*.log')
            ->in($sessionDir)
            ->sortByModifiedTime()
            ->reverseSorting();

        foreach ($finder as $file) {
            return $file->getRealPath();
        }

        return null;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
