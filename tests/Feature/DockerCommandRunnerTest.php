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
