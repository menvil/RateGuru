<?php

use App\Support\AbuseGuards\SuspiciousActivityLogger;

it('has suspicious activity log placeholder documentation', function () {
    expect(file_exists(base_path('docs/security/suspicious-activity-log.md')))->toBeTrue();
});

it('resolves suspicious activity logger as no-op service', function () {
    expect(app(SuspiciousActivityLogger::class))
        ->toBeInstanceOf(SuspiciousActivityLogger::class);
});
