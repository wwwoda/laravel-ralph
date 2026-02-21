<?php

use Woda\Ralph\ScreenManager;

test('fullName prefixes session name', function () {
    $manager = new ScreenManager(prefix: 'ralph', shell: 'zsh');

    expect($manager->fullName('my-feature'))->toBe('ralph-my-feature');
});

test('attachCommand returns correct screen command', function () {
    $manager = new ScreenManager(prefix: 'ralph', shell: 'zsh');

    $cmd = $manager->attachCommand('my-feature');

    expect($cmd)->toContain('screen -r')
        ->and($cmd)->toContain('ralph-my-feature');
});

test('listSessions returns empty when no sessions', function () {
    $manager = new ScreenManager(prefix: 'test-prefix-unlikely', shell: 'zsh');

    $sessions = $manager->listSessions();

    expect($sessions)->toBeArray();
});
