<?php

it('has import config with safe defaults', function () {
    expect(config('import.timeout_seconds'))->toBeGreaterThan(0);
    expect(config('import.max_html_bytes'))->toBeGreaterThan(0);
    expect(config('import.max_image_bytes'))->toBeGreaterThan(0);
    expect(config('import.allowed_schemes'))->toContain('https');
});
