<?php

use Woda\Ralph\DockerCommandRunner;
use Woda\Ralph\TmuxManager;

test('buildInteractive prefixes with docker compose exec -it', function () {
    $runner = new DockerCommandRunner(service: 'agent');

    $cmd = $runner->buildInteractive('tmux attach -t ralph-357');

    expect($cmd)->toBe("docker compose exec -it 'agent' tmux attach -t ralph-357");
});

test('workingDirectory returns container path regardless of host path', function () {
    $runner = new DockerCommandRunner(service: 'agent', containerWorkingDir: '/var/www/html');

    expect($runner->workingDirectory('/Volumes/Dev/hungryport/app-357'))->toBe('/var/www/html');
    expect($runner->workingDirectory(null))->toBe('/var/www/html');
});

test('TmuxManager attachCommand picks up docker prefix from runner', function () {
    $runner = new DockerCommandRunner(service: 'agent');
    $manager = new TmuxManager(prefix: 'ralph', runner: $runner);

    $cmd = $manager->attachCommand('357');

    expect($cmd)->toContain('docker compose exec -it')
        ->and($cmd)->toContain("'agent'")
        ->and($cmd)->toContain('tmux attach -t')
        ->and($cmd)->toContain('ralph-357');
});

test('TmuxManager start uses container workingDir for -c flag', function () {
    $runner = new DockerCommandRunner(service: 'agent', containerWorkingDir: '/var/www/html');

    // Indirect check: workingDirectory is what gets passed to tmux -c.
    // We can't easily assert against the actual run() without docker available,
    // but we can confirm the runner translates the host path.
    expect($runner->workingDirectory('/host/path'))->toBe('/var/www/html');
});

test('translatePath rewrites paths under composeProjectPath to containerWorkingDir', function () {
    $runner = new DockerCommandRunner(
        service: 'agent',
        containerWorkingDir: '/var/www/html',
        composeProjectPath: '/Volumes/Dev/hungryport/app-375',
    );

    expect($runner->translatePath('/Volumes/Dev/hungryport/app-375/vendor/woda/laravel-ralph/scripts/ralph-loop.cjs'))
        ->toBe('/var/www/html/vendor/woda/laravel-ralph/scripts/ralph-loop.cjs')
        ->and($runner->translatePath('/Volumes/Dev/hungryport/app-375/storage/ralph-logs/prompt-375.md'))
        ->toBe('/var/www/html/storage/ralph-logs/prompt-375.md');
});

test('translatePath returns the project root mapped to the container root', function () {
    $runner = new DockerCommandRunner(
        service: 'agent',
        containerWorkingDir: '/var/www/html',
        composeProjectPath: '/Volumes/Dev/hungryport/app-375',
    );

    expect($runner->translatePath('/Volumes/Dev/hungryport/app-375'))->toBe('/var/www/html');
});

test('translatePath leaves paths outside the project root unchanged', function () {
    $runner = new DockerCommandRunner(
        service: 'agent',
        containerWorkingDir: '/var/www/html',
        composeProjectPath: '/Volumes/Dev/hungryport/app-375',
    );

    expect($runner->translatePath('/tmp/somewhere/else.md'))->toBe('/tmp/somewhere/else.md')
        ->and($runner->translatePath('/Volumes/Dev/hungryport/app-other/foo'))
        ->toBe('/Volumes/Dev/hungryport/app-other/foo');
});

test('translatePath is identity when composeProjectPath is null', function () {
    $runner = new DockerCommandRunner(
        service: 'agent',
        containerWorkingDir: '/var/www/html',
        composeProjectPath: null,
    );

    expect($runner->translatePath('/anywhere'))->toBe('/anywhere');
});

test('translatePath tolerates a trailing slash on composeProjectPath', function () {
    $runner = new DockerCommandRunner(
        service: 'agent',
        containerWorkingDir: '/var/www/html/',
        composeProjectPath: '/Volumes/Dev/hungryport/app-375/',
    );

    expect($runner->translatePath('/Volumes/Dev/hungryport/app-375/storage/ralph-logs/foo.log'))
        ->toBe('/var/www/html/storage/ralph-logs/foo.log');
});
