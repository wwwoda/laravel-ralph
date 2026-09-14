<?php

use Woda\Ralph\NativeCommandRunner;
use Woda\Ralph\ScreenManager;

test('fullName prefixes session name', function () {
    $manager = new ScreenManager(prefix: 'ralph', shell: 'zsh', runner: new NativeCommandRunner);

    expect($manager->fullName('my-feature'))->toBe('ralph-my-feature');
});

test('attachCommand returns correct screen command', function () {
    $manager = new ScreenManager(prefix: 'ralph', shell: 'zsh', runner: new NativeCommandRunner);

    $cmd = $manager->attachCommand('my-feature');

    expect($cmd)->toContain('screen -r')
        ->and($cmd)->toContain('ralph-my-feature');
});

test('listSessions returns empty when no sessions', function () {
    $manager = new ScreenManager(prefix: 'test-prefix-unlikely', shell: 'zsh', runner: new NativeCommandRunner);

    $sessions = $manager->listSessions();

    expect($sessions)->toBeArray();
});
