<?php

it('renders the RateGuru shell', function () {
    $html = $this->get('/')->assertOk()->getContent();

    preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $matches);

    expect(strip_tags($matches[1] ?? ''))->toContain('RateGuru');
});
