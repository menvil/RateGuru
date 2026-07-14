<?php

use Illuminate\Support\Facades\Schema;

it('can reset and rerun migrations', function () {
    $this->artisan('migrate:reset', ['--force' => true])
        ->assertSuccessful();

    expect(Schema::hasTable('migrations'))->toBeTrue();

    $this->artisan('migrate', ['--force' => true])
        ->assertSuccessful();

    expect(Schema::hasTable('comment_votes'))->toBeTrue();
});
