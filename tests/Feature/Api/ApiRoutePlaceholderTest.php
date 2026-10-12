<?php

it('does not expose public api post endpoints yet', function () {
    $response = $this->getJson('/api/posts');

    expect($response->status())->not->toBe(200);
});
