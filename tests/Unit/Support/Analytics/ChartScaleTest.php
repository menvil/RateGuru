<?php

use App\Support\Analytics\ChartScale;

/*
 * A bar chart's scale: lines at round values — 1, 2 or 5 times a power of
 * ten apart — no more than four of them, the top one at or just above the
 * busiest bar; none for a chart of nobody.
 */

it('draws no more than four lines at round values, the top one at or just above the busiest bar', function (int $busiest, array $lines) {
    $scale = ChartScale::of($busiest);

    expect($scale->lines)->toBe($lines)
        ->and($scale->top)->toBe(end($lines));
})->with([
    'one' => [1, [1]],
    'two' => [2, [1, 2]],
    'four' => [4, [1, 2, 3, 4]],
    'five' => [5, [2, 4, 6]],
    'seven' => [7, [2, 4, 6, 8]],
    'ninety-nine' => [99, [50, 100]],
    'a hundred' => [100, [50, 100]],
    'a thousand and more' => [1234, [500, 1000, 1500]],
    'thirty thousand' => [30000, [10000, 20000, 30000]],
    'just past a step' => [30001, [10000, 20000, 30000, 40000]],
]);

it('draws no line for a chart of nobody, its bars all at the foot', function () {
    $scale = ChartScale::of(0);

    expect($scale->lines)->toBe([])
        ->and($scale->top)->toBe(1)
        ->and($scale->height(0))->toBe(0.0);
});

it('stands a bar as high as its count reaches up the scale', function () {
    $scale = ChartScale::of(1234);

    expect($scale->height(1234))->toBe(82.3)
        ->and($scale->height(500))->toBe(33.3)
        ->and($scale->height(1500))->toBe(100.0)
        ->and($scale->height(0))->toBe(0.0);
});
