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

it('lets a browser keep post media for a week, and never hands a file under it to PHP', function () {
    expect(preg_match('#^    location \^~ /storage/media/ \{\n(.*?)^    \}#ms', stagingPublicServerBlock(), $location))->toBe(1);

    // Not immutable: a forced variant regeneration rewrites a path in place.
    expect($location[1])
        ->toContain('add_header Cache-Control "public, max-age=604800";')
        ->not->toContain('immutable')
        ->toContain('try_files $uri =404;')
        ->not->toContain('fastcgi_pass');
});

it('accepts a 100 MB video upload, with room for the multipart envelope, in nginx and PHP alike', function () {
    expect(substr_count(stagingVhost(), "client_max_body_size 128m;\n"))->toBe(2)
        ->and(stagingVhost())->not->toContain('client_max_body_size 64m;');

    $pool = File::get(base_path('infrastructure/config/php-fpm/rateguru-staging.conf'));

    expect($pool)
        ->toContain("php_admin_value[upload_max_filesize] = 128M\n")
        ->toContain("php_admin_value[post_max_size] = 128M\n");

    expect(128 * 1024)->toBeGreaterThan((int) config('uploads.videos.max_kilobytes'));
});
