<?php

use App\Enums\VoteType;

it('contains expected vote types', function () {
    expect(VoteType::Up->value)->toBe('up');
    expect(VoteType::Down->value)->toBe('down');
});
