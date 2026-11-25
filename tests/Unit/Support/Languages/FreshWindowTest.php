<?php

use App\Support\Languages\FreshWindow;
use App\Support\Languages\LanguageRankingSettings;
use Carbon\CarbonImmutable;

/*
 * Since when a post in the viewer's language is fresh: for a member, since their previous visit, never less than a
 * day nor more than the members' window; for a guest, the guests' window.
 */

$now = CarbonImmutable::parse('2026-11-25 12:00:00');

it('counts from a member\'s previous visit, held between a day and the window', function (?string $previous, string $since) use ($now) {
    $settings = LanguageRankingSettings::fromArray(['member_window_days' => 7]);

    expect(FreshWindow::forMember($previous === null ? null : CarbonImmutable::parse($previous), $settings, $now)->toDateTimeString())->toBe($since);
})->with([
    'three days ago' => ['2026-11-22 09:30:00', '2026-11-22 09:30:00'],
    'an hour ago, held to a day' => ['2026-11-25 11:00:00', '2026-11-24 12:00:00'],
    'a month ago, held to the window' => ['2026-10-25 12:00:00', '2026-11-18 12:00:00'],
    'never, the whole window' => [null, '2026-11-18 12:00:00'],
]);

it('counts a guest\'s window back from now', function () use ($now) {
    expect(FreshWindow::forGuest(LanguageRankingSettings::fromArray(['guest_window_days' => 3]), $now)->toDateTimeString())->toBe('2026-11-22 12:00:00');
});
