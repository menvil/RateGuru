<?php

it('has admin user creation command', function () {
    $this->artisan('rateguru:admin:create', [
        '--email' => 'admin@example.test',
        '--username' => 'admin',
        '--name' => 'Admin User',
        '--password' => 'secret-password',
    ])->assertExitCode(0);
});
