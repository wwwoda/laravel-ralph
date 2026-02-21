<?php

namespace Woda\Ralph\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InitCommand extends Command
{
    protected $signature = 'ralph:init';

    protected $description = 'Initialize .claude/settings.json with Ralph sandbox config';

    public function handle(): int
    {
        $settingsPath = base_path('.claude/settings.json');

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
                $this->components->error('Existing .claude/settings.json is not valid JSON.');

                return self::FAILURE;
            }

            $merged = array_replace_recursive($existing, $requiredSettings);
            File::put($settingsPath, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->components->info('Updated .claude/settings.json with Ralph sandbox config.');
        } else {
            File::ensureDirectoryExists(dirname($settingsPath));
            File::put($settingsPath, json_encode($requiredSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->components->info('Created .claude/settings.json with Ralph sandbox config.');
        }

        return self::SUCCESS;
    }
}
