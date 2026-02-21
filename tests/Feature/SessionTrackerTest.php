<?php

use Illuminate\Support\Facades\File;
use Woda\Ralph\ScreenManager;
use Woda\Ralph\SessionTracker;

beforeEach(function () {
    $this->trackingFile = base_path('.live-agents-test');
    File::delete($this->trackingFile);
    File::delete($this->trackingFile.'.lock');

    $this->screenManager = Mockery::mock(ScreenManager::class);

    $this->tracker = new SessionTracker(
        trackingFile: $this->trackingFile,
        screenManager: $this->screenManager,
    );
});

afterEach(function () {
    File::delete($this->trackingFile);
    File::delete($this->trackingFile.'.lock');
});

test('all returns empty array when no tracking file exists', function () {
    expect($this->tracker->all())->toBe([]);
});

test('track writes session entry to file', function () {
    $this->tracker->track('test-session', [
        'name' => 'test-session',
        'prompt_source' => 'test.md',
        'working_path' => '/tmp/test',
        'session_id' => 'test-uuid-1234',
        'model' => null,
        'iterations' => 5,
        'screen_name' => 'ralph-test-session',
    ]);

    $agents = $this->tracker->all();

    expect($agents)->toHaveKey('test-session')
        ->and($agents['test-session']['name'])->toBe('test-session')
        ->and($agents['test-session']['prompt_source'])->toBe('test.md')
        ->and($agents['test-session']['session_id'])->toBe('test-uuid-1234')
        ->and($agents['test-session'])->toHaveKey('started_at');
});

test('untrack removes session entry', function () {
    $this->tracker->track('session-1', [
        'name' => 'session-1',
        'prompt_source' => 'test.md',
        'working_path' => '/tmp/test',
        'session_id' => 'uuid-1',
        'model' => null,
        'iterations' => 5,
        'screen_name' => 'ralph-session-1',
    ]);

    $this->tracker->untrack('session-1');

    expect($this->tracker->all())->toBe([]);
});

test('running filters to only sessions that are alive', function () {
    $this->tracker->track('alive', [
        'name' => 'alive',
        'prompt_source' => 'test.md',
        'working_path' => '/tmp/test',
        'session_id' => 'uuid-alive',
        'model' => null,
        'iterations' => 5,
        'screen_name' => 'ralph-alive',
    ]);

    $this->tracker->track('dead', [
        'name' => 'dead',
        'prompt_source' => 'test.md',
        'working_path' => '/tmp/test2',
        'session_id' => 'uuid-dead',
        'model' => null,
        'iterations' => 5,
        'screen_name' => 'ralph-dead',
    ]);

    $this->screenManager->shouldReceive('isRunning')
        ->with('alive')->andReturn(true);
    $this->screenManager->shouldReceive('isRunning')
        ->with('dead')->andReturn(false);

    $running = $this->tracker->running();

    expect($running)->toHaveCount(1)
        ->and($running)->toHaveKey('alive');
});

test('clean removes dead entries', function () {
    $this->tracker->track('alive', [
        'name' => 'alive',
        'prompt_source' => 'test.md',
        'working_path' => '/tmp/test',
        'session_id' => 'uuid-alive',
        'model' => null,
        'iterations' => 5,
        'screen_name' => 'ralph-alive',
    ]);

    $this->tracker->track('dead', [
        'name' => 'dead',
        'prompt_source' => 'test.md',
        'working_path' => '/tmp/test2',
        'session_id' => 'uuid-dead',
        'model' => null,
        'iterations' => 5,
        'screen_name' => 'ralph-dead',
    ]);

    $this->screenManager->shouldReceive('isRunning')
        ->with('alive')->andReturn(true);
    $this->screenManager->shouldReceive('isRunning')
        ->with('dead')->andReturn(false);

    $cleaned = $this->tracker->clean();

    expect($cleaned)->toBe(['dead'])
        ->and($this->tracker->all())->toHaveCount(1)
        ->and($this->tracker->all())->toHaveKey('alive');
});

test('isRunning checks screen manager', function () {
    $this->tracker->track('test', [
        'name' => 'test',
        'prompt_source' => 'test.md',
        'working_path' => '/tmp/test',
        'session_id' => 'uuid-test',
        'model' => null,
        'iterations' => 5,
        'screen_name' => 'ralph-test',
    ]);

    $this->screenManager->shouldReceive('isRunning')
        ->with('test')->andReturn(true);

    expect($this->tracker->isRunning('test'))->toBeTrue();
    expect($this->tracker->isRunning('nonexistent'))->toBeFalse();
});

test('get returns session data or null', function () {
    $this->tracker->track('test', [
        'name' => 'test',
        'prompt_source' => 'test.md',
        'working_path' => '/tmp/test',
        'session_id' => 'uuid-test',
        'model' => null,
        'iterations' => 5,
        'screen_name' => 'ralph-test',
    ]);

    expect($this->tracker->get('test'))->not()->toBeNull()
        ->and($this->tracker->get('test')['name'])->toBe('test')
        ->and($this->tracker->get('test')['session_id'])->toBe('uuid-test')
        ->and($this->tracker->get('nonexistent'))->toBeNull();
});
