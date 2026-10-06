<?php

use App\Filament\Support\AdminNavigationGroup;

it('defines stable admin navigation group names', function () {
    expect(AdminNavigationGroup::OVERVIEW)->toBe('Overview');
    expect(AdminNavigationGroup::MODERATION)->toBe('Moderation');
    expect(AdminNavigationGroup::CONTENT)->toBe('Content');
    expect(AdminNavigationGroup::LOCALIZATION)->toBe('Localization');
    expect(AdminNavigationGroup::CONFIGURATION)->toBe('Configuration');
    expect(AdminNavigationGroup::SYSTEM)->toBe('System');
});

it('exposes all navigation groups in the Admin v2 display order', function () {
    expect(AdminNavigationGroup::all())->toBe([
        'Overview',
        'Moderation',
        'Content',
        'Localization',
        'Configuration',
        'System',
    ]);
});

it('no longer has the sections Admin v2 retired', function () {
    expect(defined(AdminNavigationGroup::class.'::USERS'))->toBeFalse()
        ->and(defined(AdminNavigationGroup::class.'::TAXONOMY'))->toBeFalse();
});
