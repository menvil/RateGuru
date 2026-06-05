<?php

it('has a dry run mode for migrating legacy rating votes', function () {
    $this->artisan('rateguru:rating:migrate-legacy-votes', [
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);
});
