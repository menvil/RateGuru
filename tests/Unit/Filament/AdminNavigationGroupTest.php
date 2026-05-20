<?php

use App\Filament\Support\AdminNavigationGroup;

it('defines stable admin navigation group names', function () {
    expect(AdminNavigationGroup::CONTENT)->toBe('Content');
    expect(AdminNavigationGroup::MODERATION)->toBe('Moderation');
    expect(AdminNavigationGroup::USERS)->toBe('Users');
    expect(AdminNavigationGroup::TAXONOMY)->toBe('Taxonomy');
    expect(AdminNavigationGroup::SYSTEM)->toBe('System');
});

it('exposes all navigation groups in display order', function () {
    expect(AdminNavigationGroup::all())->toBe([
        'Content',
        'Moderation',
        'Users',
        'Taxonomy',
        'System',
    ]);
});
