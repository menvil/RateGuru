<?php

use App\Models\ModerationLog;

it('casts moderation log metadata to array', function () {
    $log = new ModerationLog(['metadata' => ['x' => 1]]);

    expect($log->metadata)->toBe(['x' => 1]);
});
