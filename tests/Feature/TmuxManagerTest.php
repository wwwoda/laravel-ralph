<?php

use Woda\Ralph\NativeCommandRunner;
use Woda\Ralph\TmuxManager;

test('fullName prefixes session name', function () {
    $manager = new TmuxManager(prefix: 'ralph', runner: new NativeCommandRunner);

    expect($manager->fullName('my-feature'))->toBe('ralph-my-feature');
});

test('attachCommand returns correct tmux command', function () {
    $manager = new TmuxManager(prefix: 'ralph', runner: new NativeCommandRunner);

    $cmd = $manager->attachCommand('my-feature');

    expect($cmd)->toContain('tmux attach -t')
        ->and($cmd)->toContain('ralph-my-feature');
});

test('listSessions returns array (empty or only prefix-matched)', function () {
    $manager = new TmuxManager(prefix: 'test-prefix-unlikely-'.uniqid(), runner: new NativeCommandRunner);

    $sessions = $manager->listSessions();

    expect($sessions)->toBeArray();
});
