<?php

it('has recalculate post counters command', function () {
    $this->artisan('rateguru:recalculate-post-counters')
        ->assertExitCode(0);
});
