<?php

use App\Queries\Feed\FeedQuery;

it('resolves feed query from container', function () {
    $query = app(FeedQuery::class);

    expect($query)->toBeInstanceOf(FeedQuery::class);
});
