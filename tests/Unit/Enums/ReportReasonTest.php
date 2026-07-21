<?php

use App\Enums\ReportReason;

it('contains expected report reasons', function () {
    expect(ReportReason::Spam->value)->toBe('spam');
    expect(ReportReason::Offensive->value)->toBe('offensive');
    expect(ReportReason::Fake->value)->toBe('fake');
    expect(ReportReason::Copyright->value)->toBe('copyright');
    expect(ReportReason::WrongCategory->value)->toBe('wrong_category');
    expect(ReportReason::Other->value)->toBe('other');
});
