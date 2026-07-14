<?php

it('has theme config with supported preferences', function () {
    expect(config('themes.preferences'))->toContain('system');
    expect(config('themes.preferences'))->toContain('light');
    expect(config('themes.preferences'))->toContain('dark');
    expect(config('themes.default'))->toBe('system');
});

it('has theme config with applied themes', function () {
    expect(config('themes.applied'))->toContain('light');
    expect(config('themes.applied'))->toContain('dark');
    expect(config('themes.applied'))->not->toContain('system');
});
