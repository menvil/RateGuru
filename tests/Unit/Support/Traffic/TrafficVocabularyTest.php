<?php

use App\Enums\TrafficChannel;
use App\Enums\TrafficCostUnit;
use App\Enums\TrafficEvent;
use App\Support\Traffic\Worth\TrafficStep;

it('gives every event a column of its own and lets twelve moments and the account be reported', function () {
    expect(array_map(fn (TrafficEvent $event): string => $event->column(), TrafficEvent::cases()))
        ->each->toMatch('/^[a-z_]+_at$/')
        ->and(array_map(fn (TrafficEvent $event): string => $event->value, TrafficEvent::postbackEvents()))->toBe([
            'opened', 'started', 'halfway', 'completed', 'goal', 'email', 'shared', 'replayed',
            'post_opened', 'post_voted', 'post_rated', 'feed_voted', 'account',
        ]);
});

it('tells paid channels from unpaid ones', function () {
    expect(array_values(array_map(fn (TrafficChannel $channel): string => $channel->value, array_filter(TrafficChannel::cases(), fn (TrafficChannel $channel): bool => $channel->isPaid()))))
        ->toBe(['network', 'placement']);
});

it('prices a click from a price per thousand', function () {
    expect(TrafficCostUnit::Cpm->divisor())->toBe(1000)->and(TrafficCostUnit::Click->divisor())->toBe(1);
});

it('names the rounds of a game, the last one being the finished game', function () {
    expect(TrafficStep::all(5))->toBe([
        'opened', 'started', 'round_1', 'round_2', 'round_3', 'round_4', 'completed',
        'email', 'shared', 'replayed', 'next_game', 'page', 'feed',
        'post_opened', 'post_voted', 'post_rated', 'feed_voted',
        'signup', 'account', 'login',
    ])->and(TrafficStep::round(5, 5))->toBe('completed')
        ->and(TrafficStep::round(2, 5))->toBe('round_2');
});

it('puts halfway at the middle round, whatever the length of the game', function (int $rounds, string $step) {
    expect(TrafficStep::halfway($rounds))->toBe($step)
        ->and(TrafficStep::ofEvent(TrafficEvent::Halfway, $rounds))->toBe($step);
})->with([
    [1, 'completed'],
    [2, 'round_1'],
    [3, 'round_2'],
    [5, 'round_3'],
    [8, 'round_4'],
]);

it('gives no step to the goal, the sign-up form or the bonuses', function () {
    expect(TrafficStep::ofEvent(TrafficEvent::Goal, 5))->toBeNull()
        ->and(TrafficStep::ofEvent(TrafficEvent::SignupForm, 5))->toBeNull()
        ->and(TrafficStep::ofEvent(TrafficEvent::PostPublished, 5))->toBeNull()
        ->and(TrafficStep::ofEvent(TrafficEvent::Started, 5))->toBe('started');
});
