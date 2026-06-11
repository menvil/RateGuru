<?php

it('runs observability health command', function () {
    $this->artisan('rateguru:observability:health')
        ->assertExitCode(0);
});

it('shows request id config status', function () {
    $this->artisan('rateguru:observability:health')
        ->expectsOutputToContain('X-Request-Id')
        ->assertExitCode(0);
});

it('shows redaction status', function () {
    $this->artisan('rateguru:observability:health')
        ->expectsOutputToContain('redaction')
        ->assertExitCode(0);
});
