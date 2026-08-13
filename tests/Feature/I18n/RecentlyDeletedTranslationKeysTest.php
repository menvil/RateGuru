<?php

use Illuminate\Support\Facades\App;

it('exposes recently-deleted keys in every locale', function (string $locale) {
    App::setLocale($locale);

    foreach ([
        'ui.recently_deleted.title',
        'ui.recently_deleted.description',
        'ui.recently_deleted.empty_title',
        'ui.recently_deleted.empty_description',
        'ui.recently_deleted.deleted_on',
        'ui.recently_deleted.expired',
        'ui.recently_deleted.restore',
        'ui.recently_deleted.restored',
        'ui.recently_deleted.unavailable',
    ] as $key) {
        expect(__($key))->not->toBe($key);
    }
})->with(['en', 'ru', 'bg']);

it('pluralizes russian days-left correctly across teen and composite counts', function () {
    App::setLocale('ru');

    $line = fn (int $count): string => trans_choice('ui.recently_deleted.days_left', $count, [
        'count' => $count,
        'date' => '1 Jan 2027',
    ]);

    // Russian one|few|many rules: 21 -> день, 22 -> дня, 25 -> дней,
    // and the teens (11/12) always take the many form. The zero state is
    // its own key so no explicit {0} shifts the positional indexes.
    expect(__('ui.recently_deleted.less_than_day', ['date' => '1 Jan 2027']))->toContain('меньше суток')
        ->and($line(1))->toContain('1 день')
        ->and($line(3))->toContain('3 дня')
        ->and($line(5))->toContain('5 дней')
        ->and($line(11))->toContain('11 дней')
        ->and($line(12))->toContain('12 дней')
        ->and($line(21))->toContain('21 день')
        ->and($line(22))->toContain('22 дня')
        ->and($line(25))->toContain('25 дней');
});

it('pluralizes english and bulgarian days-left for singular and plural counts', function (string $locale, string $one, string $many) {
    App::setLocale($locale);

    $line = fn (int $count): string => trans_choice('ui.recently_deleted.days_left', $count, [
        'count' => $count,
        'date' => '1 Jan 2027',
    ]);

    expect($line(1))->toContain($one)
        ->and($line(21))->toContain($many);
})->with([
    'en' => ['en', '1 day left', '21 days left'],
    'bg' => ['bg', '1 ден', '21 дни'],
]);
