<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

test('ralph:status shows empty when no sessions tracked', function () {
    $this->artisan('ralph:status')
        ->expectsOutputToContain('No sessions tracked')
        ->assertExitCode(0);
});

test('ralph:status with --json outputs json when no sessions', function () {
    $this->artisan('ralph:status --json')
        ->expectsOutputToContain('No sessions tracked')
        ->assertExitCode(0);
});

test('ralph:status with --clean works when no sessions', function () {
    $this->artisan('ralph:status --clean')
        ->expectsOutputToContain('No dead entries found')
        ->assertExitCode(0);
});

test('ralph:kill shows error when no sessions tracked', function () {
    $this->artisan('ralph:kill --force some-session')
        ->assertExitCode(0);
});

test('ralph:kill --all works when no sessions', function () {
    $this->artisan('ralph:kill --all --force')
        ->expectsOutputToContain('No sessions to kill')
        ->assertExitCode(0);
});

test('ralph:init creates settings.json with --shared flag', function () {
    $claudeDir = base_path('.claude');
    $settingsPath = $claudeDir.'/settings.json';

    // Ensure clean state
    File::deleteDirectory($claudeDir);

    $this->artisan('ralph:init --shared')
        ->expectsOutputToContain('Created .claude/settings.json')
        ->assertExitCode(0);

    expect(File::exists($settingsPath))->toBeTrue();

    /** @var array<string, mixed> $settings */
    $settings = json_decode(File::get($settingsPath), true);

    expect($settings['permissions']['defaultMode'])->toBe('acceptEdits')
        ->and($settings['sandbox']['enabled'])->toBeTrue()
        ->and($settings['sandbox']['autoAllowBashIfSandboxed'])->toBeTrue();

    // Cleanup
    File::deleteDirectory($claudeDir);
});

test('ralph:init creates settings.local.json with --local flag', function () {
    $claudeDir = base_path('.claude');
    $localPath = $claudeDir.'/settings.local.json';

    // Ensure clean state
    File::deleteDirectory($claudeDir);

    $this->artisan('ralph:init --local')
        ->expectsOutputToContain('Created .claude/settings.local.json')
        ->assertExitCode(0);

    expect(File::exists($localPath))->toBeTrue();

    /** @var array<string, mixed> $settings */
    $settings = json_decode(File::get($localPath), true);

    expect($settings['sandbox']['enabled'])->toBeTrue()
        ->and($settings['sandbox']['autoAllowBashIfSandboxed'])->toBeTrue();

    // Cleanup
    File::deleteDirectory($claudeDir);
});

test('ralph:init merges with existing settings', function () {
    $claudeDir = base_path('.claude');
    $settingsPath = $claudeDir.'/settings.json';

    File::ensureDirectoryExists($claudeDir);
    File::put($settingsPath, json_encode([
        'customKey' => 'preserved',
        'permissions' => ['existingPerm' => true],
    ], JSON_PRETTY_PRINT));

    $this->artisan('ralph:init --shared')
        ->expectsOutputToContain('Updated .claude/settings.json')
        ->assertExitCode(0);

    /** @var array<string, mixed> $settings */
    $settings = json_decode(File::get($settingsPath), true);

    expect($settings['customKey'])->toBe('preserved')
        ->and($settings['permissions']['defaultMode'])->toBe('acceptEdits')
        ->and($settings['permissions']['existingPerm'])->toBeTrue()
        ->and($settings['sandbox']['enabled'])->toBeTrue()
        ->and($settings['sandbox']['autoAllowBashIfSandboxed'])->toBeTrue();

    // Cleanup
    File::deleteDirectory($claudeDir);
});

test('ralph:start rejects invalid permission mode', function () {
    $this->artisan('ralph:start --permission-mode=invalid --once --prompt "test" test-session')
        ->expectsOutputToContain("Invalid permission mode 'invalid'")
        ->assertExitCode(1);
});

test('ralph:init fails on invalid existing json', function () {
    $claudeDir = base_path('.claude');
    $settingsPath = $claudeDir.'/settings.json';

    File::ensureDirectoryExists($claudeDir);
    File::put($settingsPath, 'not valid json{{{');

    $this->artisan('ralph:init --shared')
        ->expectsOutputToContain('not valid JSON')
        ->assertExitCode(1);

    // Cleanup
    File::deleteDirectory($claudeDir);
});

test('ralph:start --once skips docker validation and checks host binaries', function () {
    config()->set('ralph.docker.enabled', true);

    Process::fake([
        'which node' => Process::result(exitCode: 1),
        'which claude' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('ralph:start --once --prompt "test" test-session')
        ->expectsOutputToContain('Missing required binaries: node, claude')
        ->assertExitCode(1);

    Process::assertNotRan('which docker');
});

test('ralph:start in docker mode fails when the service lacks node or claude', function () {
    config()->set('ralph.docker.enabled', true);
    config()->set('ralph.docker.service', 'agent');
    config()->set('ralph.session.manager', 'tmux');

    Process::fake([
        'which docker' => Process::result(),
        'docker compose ps *' => Process::result(),
        "docker compose exec -T 'agent' sh -c 'command -v node'" => Process::result(),
        "docker compose exec -T 'agent' sh -c 'command -v claude'" => Process::result(exitCode: 127),
        "docker compose exec -T 'agent' sh -c 'command -v tmux'" => Process::result(exitCode: 127),
    ]);

    $this->artisan('ralph:start --prompt "test" test-session')
        ->expectsOutputToContain("Missing required binaries in compose service 'agent': claude, tmux")
        ->assertExitCode(1);
});
