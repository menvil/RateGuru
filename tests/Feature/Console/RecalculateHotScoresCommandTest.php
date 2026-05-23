<?php

it('has recalculate hot scores command', function () {
    $this->artisan('posts:recalculate-hot-scores')
        ->assertExitCode(0);
});
