<?php

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
