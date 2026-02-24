<?php

namespace Woda\Ralph\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\select;

class InitCommand extends Command
{
    protected $signature = 'ralph:init
        {--local : Write to settings.local.json}
        {--shared : Write to settings.json}';

    protected $description = 'Initialize Claude settings with Ralph sandbox config';

    public function handle(): int
    {
        $settingsFile = $this->resolveTargetFile();
        $settingsPath = base_path('.claude/'.$settingsFile);

        /** @var array<string, mixed> $requiredSettings */
        $requiredSettings = [
            'permissions' => ['defaultMode' => 'acceptEdits'],
            'sandbox' => [
                'enabled' => true,
                'autoAllowBashIfSandboxed' => true,
            ],
        ];

        if (File::exists($settingsPath)) {
            /** @var array<string, mixed>|null $existing */
            $existing = json_decode(File::get($settingsPath), true);

            if (! is_array($existing)) {
                $this->components->error("Existing .claude/{$settingsFile} is not valid JSON.");

                return self::FAILURE;
            }

            $merged = array_replace_recursive($existing, $requiredSettings);
            File::put($settingsPath, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->components->info("Updated .claude/{$settingsFile} with Ralph sandbox config.");
        } else {
            File::ensureDirectoryExists(dirname($settingsPath));
            File::put($settingsPath, json_encode($requiredSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->components->info("Created .claude/{$settingsFile} with Ralph sandbox config.");
        }

        return self::SUCCESS;
    }

    private function resolveTargetFile(): string
    {
        if ($this->option('local')) {
            return 'settings.local.json';
        }

        if ($this->option('shared')) {
            return 'settings.json';
        }

        /** @var string */
        return select(
            label: 'Which settings file should Ralph configure?',
            options: [
                'settings.json' => 'settings.json — shared, committed to repo',
                'settings.local.json' => 'settings.local.json — personal, gitignored',
            ],
        );
    }
}
