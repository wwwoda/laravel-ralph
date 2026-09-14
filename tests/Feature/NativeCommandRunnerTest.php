<?php

use Woda\Ralph\NativeCommandRunner;

test('workingDirectory returns the host path verbatim', function () {
    $runner = new NativeCommandRunner;

    expect($runner->workingDirectory('/Users/dave/project'))->toBe('/Users/dave/project')
        ->and($runner->workingDirectory(null))->toBeNull();
});

test('translatePath is identity for the native runner', function () {
    $runner = new NativeCommandRunner;

    expect($runner->translatePath('/Users/dave/project/vendor/foo/script.cjs'))
        ->toBe('/Users/dave/project/vendor/foo/script.cjs');
});

test('buildInteractive returns the command unchanged', function () {
    $runner = new NativeCommandRunner;

    expect($runner->buildInteractive('tmux attach -t ralph-foo'))
        ->toBe('tmux attach -t ralph-foo');
});
