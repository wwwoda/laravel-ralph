<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Ralph\Contracts\SessionManager;
use Woda\Ralph\SessionRouter;
use Woda\Ralph\SessionTracker;

beforeEach(function () {
    $this->recoveryDir = sys_get_temp_dir().'/ralph-recovery-'.uniqid();
    File::ensureDirectoryExists($this->recoveryDir);
    $this->recoveryManager = Mockery::mock(SessionManager::class);
    $this->recoveryTracker = new SessionTracker($this->recoveryDir.'/registry.json', $this->recoveryManager);
});

afterEach(function () {
    File::deleteDirectory($this->recoveryDir);
});

test('recovery leaves quota paused sessions untouched and clean retains them', function () {
    $statePath = $this->recoveryDir.'/state.json';
    File::put($statePath, json_encode(['status' => 'waiting_for_quota', 'next_attempt_at' => (time() + 3600) * 1000]));
    $this->recoveryTracker->track('lane', ['name' => 'lane', 'working_path' => $this->recoveryDir,
        'launch_command' => 'saved command', 'state_path' => $statePath, 'auto_restart' => true]);
    $this->recoveryManager->shouldNotReceive('start');
    $this->recoveryTracker->recover();
    expect($this->recoveryTracker->clean())->toBe([])
        ->and($this->recoveryTracker->get('lane'))->not->toBeNull();
});

test('recovery restarts a dead eligible session once and respects a live owner', function () {
    $this->recoveryTracker->track('lane', ['name' => 'lane', 'working_path' => $this->recoveryDir,
        'launch_command' => 'saved command', 'auto_restart' => true]);
    $this->recoveryManager->shouldReceive('isRunning')->with('lane')->twice()->andReturn(false, true);
    $this->recoveryManager->shouldReceive('start')->with('lane', 'saved command', $this->recoveryDir)->once();
    $this->recoveryTracker->recover();
    $this->recoveryTracker->recover();
});

test('cancellation stops a lane and prevents its next wakeup', function () {
    $statePath = $this->recoveryDir.'/state.json';
    $this->recoveryTracker->track('lane', ['name' => 'lane', 'working_path' => $this->recoveryDir,
        'launch_command' => 'saved command', 'state_path' => $statePath, 'auto_restart' => true]);
    $this->recoveryManager->shouldReceive('kill')->with('lane')->once();
    $this->recoveryManager->shouldNotReceive('start');
    $this->recoveryTracker->cancel('lane');
    $this->recoveryTracker->recover();
    expect($this->recoveryTracker->get('lane')['auto_restart'])->toBeFalse()
        ->and(File::exists($statePath.'.cancel'))->toBeTrue();
});

test('cancellation records a cancelled runtime status', function () {
    $statePath = $this->recoveryDir.'/state.json';
    File::put($statePath, json_encode(['status' => 'running', 'attempts' => 2, 'session_id' => 'thread']));
    $this->recoveryTracker->track('lane', ['name' => 'lane', 'working_path' => $this->recoveryDir,
        'launch_command' => 'saved command', 'state_path' => $statePath, 'auto_restart' => true]);
    $this->recoveryManager->shouldReceive('kill')->with('lane')->once();
    $this->recoveryTracker->cancel('lane');
    expect(json_decode(File::get($statePath), true))->toMatchArray(['status' => 'cancelled', 'attempts' => 2, 'session_id' => 'thread']);
});

test('resume refuses finished work and reopens auth blocks', function () {
    $statePath = $this->recoveryDir.'/state.json';
    $this->recoveryTracker->track('lane', ['name' => 'lane', 'working_path' => $this->recoveryDir,
        'launch_command' => 'saved command', 'state_path' => $statePath, 'auto_restart' => false]);
    foreach (['complete', 'failed', 'blocked_work', 'blocked_config'] as $status) {
        File::put($statePath, json_encode(['status' => $status]));
        expect(fn () => $this->recoveryTracker->resume('lane'))->toThrow(RuntimeException::class, $status);
    }
    File::put($statePath, json_encode(['status' => 'blocked_auth', 'session_id' => 'thread']));
    $this->recoveryManager->shouldReceive('isRunning')->with('lane')->andReturn(false);
    $this->recoveryManager->shouldReceive('start')->with('lane', 'saved command', $this->recoveryDir)->once();
    $this->recoveryTracker->resume('lane');
    expect(json_decode(File::get($statePath), true))->toMatchArray(['status' => 'waiting', 'session_id' => 'thread'])
        ->and($this->recoveryTracker->get('lane')['auto_restart'])->toBeTrue();
});

test('a session keeps its agent until it is untracked', function () {
    $statePath = $this->recoveryDir.'/state.json';
    File::put($statePath, json_encode(['status' => 'complete']));
    $this->recoveryTracker->track('lane', ['name' => 'lane', 'agent' => 'claude', 'working_path' => $this->recoveryDir,
        'launch_command' => 'saved command', 'state_path' => $statePath, 'auto_restart' => true]);
    $this->recoveryManager->shouldReceive('isRunning')->with('lane')->andReturn(false);
    $this->recoveryManager->shouldNotReceive('start');
    expect(fn () => $this->recoveryTracker->start('lane', ['name' => 'lane', 'agent' => 'codex', 'working_path' => $this->recoveryDir,
        'launch_command' => 'new command', 'state_path' => $statePath]))->toThrow(RuntimeException::class, "agent 'claude'");
});

test('a blocked lane is finished for cleanup and never restarted', function () {
    $statePath = $this->recoveryDir.'/state.json';
    File::put($statePath, json_encode(['status' => 'blocked_work']));
    $this->recoveryTracker->track('lane', ['name' => 'lane', 'working_path' => $this->recoveryDir,
        'launch_command' => 'saved command', 'state_path' => $statePath, 'auto_restart' => true]);
    $this->recoveryManager->shouldReceive('isRunning')->with('lane')->andReturn(false);
    $this->recoveryManager->shouldNotReceive('start');
    $this->recoveryTracker->recover();
    expect($this->recoveryTracker->clean())->toBe(['lane']);
});

test('session routing checks the tracked lane rather than the callers container', function () {
    Process::fake();
    $manager = (new SessionRouter)->forRecord(['docker' => true, 'working_path' => '/tmp/lane-two', 'service' => 'agent', 'manager' => 'tmux']);
    $manager->isRunning('lane-two');
    Process::assertRan(fn (PendingProcess $process) => $process->path === '/tmp/lane-two'
        && str_contains($process->command, 'docker compose exec')
        && str_contains($process->command, 'ralph-lane-two'));
});

test('missing prompt file fails before an agent starts', function () {
    Process::fake();
    $this->artisan('ralph:start lane --once --prompt-file=/missing/lane.md')->expectsOutputToContain('Prompt file does not exist')->assertExitCode(1);
    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'ralph-loop.cjs'));
});

test('Codex selection checks Codex instead of Claude', function () {
    Process::fake(['which codex' => Process::result(exitCode: 1), '*' => Process::result()]);
    $this->artisan('ralph:start lane --agent=codex --once --prompt=test')->expectsOutputToContain('Missing required binaries: codex')->assertExitCode(1);
    Process::assertNotRan('which claude');
});
