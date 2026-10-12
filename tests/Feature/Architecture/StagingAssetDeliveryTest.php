<?php

/*
 * How the staging vhost delivers the built front end: compressed, and cached
 * for good where Vite fingerprints the file. Out of the box nginx compresses
 * HTML only, which sent the stylesheet and Livewire's script at full size.
 */

/** The vhost's public HTTPS server block, where visitors arrive. */
function stagingPublicServerBlock(): string
{
    expect(preg_match('/^server \{\n(?:(?!^\}).)*?listen 443 ssl.*?^\}/ms', stagingVhost(), $matches))
        ->toBe(1, 'the staging vhost has no public HTTPS server block');

    return $matches[0];
}

it('compresses the stylesheet, scripts and other text it serves', function () {
    $server = stagingPublicServerBlock();

    expect($server)->toMatch('/^\s*gzip on;$/m')
        ->and(preg_match('/^\s*gzip_types ([^;]+);$/m', $server, $types))->toBe(1);

    expect(explode(' ', $types[1]))->toContain('text/css', 'application/javascript', 'image/svg+xml', 'application/json');
});

it('lets a browser keep every fingerprinted build file for good', function () {
    expect(preg_match('#^    location /build/assets/ \{\n(.*?)^    \}#ms', stagingPublicServerBlock(), $location))->toBe(1);

    expect($location[1])
        ->toContain('add_header Cache-Control "public, max-age=31536000, immutable";')
        // A missing build file is a 404, never a page the application renders.
        ->toContain('try_files $uri =404;');
});
