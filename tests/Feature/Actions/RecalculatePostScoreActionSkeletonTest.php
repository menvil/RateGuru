<?php

use App\Actions\Ranking\RecalculatePostScoreAction;
use Illuminate\Support\Facades\Schema;

it('has recalculate post score action with handle method', function () {
    $action = app(RecalculatePostScoreAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});

it('posts table has hot score column', function () {
    expect(Schema::hasColumn('posts', 'hot_score'))->toBeTrue();
});
