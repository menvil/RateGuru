<?php

it('has api route file placeholder', function () {
    expect(file_exists(base_path('routes/api.php')))->toBeTrue();

    $content = file_get_contents(base_path('routes/api.php'));

    expect($content)->toContain('API endpoints are intentionally not implemented yet');
});

it('does not expose public api post endpoints yet', function () {
    $response = $this->getJson('/api/posts');

    expect($response->status())->not->toBe(200);
});

it('application boots with api route placeholder', function () {
    $response = $this->get('/');

    expect([200, 302, 404])->toContain($response->status());
});
