<?php

use App\Enums\PostStatus;

it('contains expected post statuses', function () {
    expect(PostStatus::Draft->value)->toBe('draft');
    expect(PostStatus::Pending->value)->toBe('pending');
    expect(PostStatus::Published->value)->toBe('published');
    expect(PostStatus::Hidden->value)->toBe('hidden');
    expect(PostStatus::Rejected->value)->toBe('rejected');
    expect(PostStatus::Deleted->value)->toBe('deleted');
});
