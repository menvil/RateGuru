<?php

it('runs full database seeder successfully', function () {
    $this->artisan('db:seed')
        ->assertExitCode(0);
});
