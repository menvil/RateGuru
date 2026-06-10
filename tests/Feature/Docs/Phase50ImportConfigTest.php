<?php

it('has import config with safe defaults', function () {
    expect(config('import.timeout_seconds'))->toBeGreaterThan(0)->toBeLessThanOrEqual(30);
    expect(config('import.connect_timeout_seconds'))->toBeGreaterThan(0)->toBeLessThanOrEqual(10);
    expect(config('import.max_redirects'))->toBeGreaterThan(0)->toBeLessThanOrEqual(10);
    expect(config('import.max_html_bytes'))->toBeGreaterThan(0);
    expect(config('import.max_image_bytes'))->toBeGreaterThan(0);
    expect(config('import.allowed_schemes'))->toContain('https');
    expect(config('import.allowed_schemes'))->not->toContain('file');
    expect(config('import.allowed_schemes'))->not->toContain('ftp');
});
