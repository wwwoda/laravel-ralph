<?php

namespace Woda\Ralph\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class WatchInstallCommand extends Command
{
    protected $signature = 'ralph:watch-install {--remove : Remove this repository watcher} {--print : Print the launchd definition without installing}';

    protected $description = 'Install a macOS launchd recovery tick for this repository';

    public function handle(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->components->error('Use a system scheduler to run ralph:watch each minute on this platform.');

            return self::FAILURE;
        }
        $home = getenv('HOME');
        if (! is_string($home) || $home === '') {
            return self::FAILURE;
        }
        /** @var string $trackingFile */
        $trackingFile = config('ralph.tracking.file');
        $label = 'at.woda.ralph.'.substr(hash('sha256', base_path().'|'.$trackingFile), 0, 12);
        $file = $home.'/Library/LaunchAgents/'.$label.'.plist';
        $domain = 'gui/'.posix_getuid();
        if ($this->option('remove')) {
            Process::run(['launchctl', 'bootout', $domain.'/'.$label]);
            if (File::exists($file) && ! File::delete($file)) {
                $this->components->error("Could not remove {$file}.");

                return self::FAILURE;
            }
            $this->components->info("Recovery watcher '{$label}' removed.");

            return self::SUCCESS;
        }
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $php = $escape(PHP_BINARY);
        $artisan = $escape(base_path('artisan'));
        $directory = $escape(base_path());
        $path = $escape($this->watcherPath());
        $tracking = $escape($trackingFile);
        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0"><dict>
          <key>Label</key><string>{$label}</string>
          <key>ProgramArguments</key><array><string>{$php}</string><string>{$artisan}</string><string>ralph:watch</string></array>
          <key>WorkingDirectory</key><string>{$directory}</string>
          <key>EnvironmentVariables</key><dict><key>PATH</key><string>{$path}</string><key>RALPH_TRACKING_FILE</key><string>{$tracking}</string></dict>
          <key>StartInterval</key><integer>60</integer>
          <key>RunAtLoad</key><true/>
        </dict></plist>
        XML;
        if ($this->option('print')) {
            $this->line($xml);

            return self::SUCCESS;
        }
        File::ensureDirectoryExists(dirname($file));
        File::replace($file, $xml);
        Process::run(['launchctl', 'bootout', $domain.'/'.$label]);
        $result = Process::run(['launchctl', 'bootstrap', $domain, $file]);
        if (! $result->successful()) {
            $this->components->error('Could not install the recovery watcher.');

            return self::FAILURE;
        }
        $this->components->info('Recovery watcher installed for this checkout. Keep it available on disk.');

        return self::SUCCESS;
    }

    /**
     * launchd keeps this PATH after the installing shell is gone, so per-shell
     * entries (fnm/nvm multishells) are replaced by the binaries' real directories.
     */
    private function watcherPath(): string
    {
        $directories = [];
        foreach (['node', 'tmux', 'screen', 'docker', 'git', 'gh', 'claude', 'codex'] as $binary) {
            $result = Process::run(['sh', '-c', 'command -v '.$binary]);
            if (! $result->successful()) {
                continue;
            }
            $found = trim($result->output());
            $resolved = realpath($found);
            $directories[] = is_string($resolved) && is_file(dirname($resolved).'/'.$binary) ? dirname($resolved) : dirname($found);
        }
        foreach (explode(':', (string) getenv('PATH')) as $entry) {
            if ($entry !== '' && ! str_contains($entry, '/fnm_multishells/') && ! str_contains($entry, '/.codex/tmp/')) {
                $directories[] = $entry;
            }
        }

        return implode(':', array_values(array_unique($directories)));
    }
}
