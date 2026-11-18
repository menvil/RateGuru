<?php

use App\Enums\TrafficBlockVerdict;
use App\Support\Traffic\Blocking\BlockJudge;
use App\Support\Traffic\Blocking\BlockRule;
use App\Support\Traffic\Reports\ReportFigures;
use Carbon\CarbonImmutable;

/**
 * The block rule's verdicts on one site, pure: bots against the lower of the
 * rule's share and a share of the source's own average, never below the
 * rule's floor, whatever the average; losing only so many
 * days after the site's first click, and — where its campaign or its source
 * has a target price of an account — only once it has spent so many times
 * that target, else the rule's spend or clicks; a weak start before the
 * money is judged. Bots and a weak start wait for neither.
 */
const JUDGED_AT = '2026-11-18 12:00:00';

/** The rule's general thresholds as the settings row starts, with any changed. */
function judgedBlockRule(array $changes = []): array
{
    return BlockRule::effective([
        'block_bot_clicks' => 30, 'block_open_below' => 50, 'block_open_below_average' => 50, 'block_open_floor' => 5, 'block_min_spend' => '2', 'block_min_clicks' => 200,
        'block_roi_below' => -40, 'block_grace_days' => 3, 'block_target_multiple' => '2', 'block_start_clicks' => 60, 'block_start_percent' => 60,
    ], $changes);
}

/** @return array{verdict: TrafficBlockVerdict|null, state: string} */
function judgedSite(array $figures, array $rule = [], ?float $startRate = null, ?float $openRate = null, bool $worthKnown = false, ?string $target = null, ?string $firstClick = null, ?string $windowFrom = null): array
{
    return BlockJudge::judge(new ReportFigures(...$figures), judgedBlockRule($rule), $startRate, $openRate, $worthKnown, $target, $firstClick === null ? null : CarbonImmutable::parse($firstClick), CarbonImmutable::parse(JUDGED_AT), $windowFrom === null ? null : CarbonImmutable::parse($windowFrom, 'UTC'));
}

it('judges a site at the edges of each rule, with no source average, target or first click to go by', function (array $figures, ?float $startRate, bool $worthKnown, ?TrafficBlockVerdict $verdict, string $state) {
    expect(judgedSite($figures, startRate: $startRate, worthKnown: $worthKnown))->toBe(['verdict' => $verdict, 'state' => $state]);
})->with([
    'bots: 30 clicks, 14 opened' => [['visits' => 30, 'opened' => 14], null, false, TrafficBlockVerdict::Bots, 'bots'],
    'not bots: 15 of 30 opened' => [['visits' => 30, 'opened' => 15], null, false, null, 'early'],
    'not bots yet: 29 clicks' => [['visits' => 29, 'opened' => 0], null, false, null, 'early'],
    'losing: −50 %' => [['visits' => 100, 'opened' => 90, 'spend' => '2', 'valueReal' => '1'], 0.5, true, TrafficBlockVerdict::Losing, 'losing'],
    'not judged before accounts are measured' => [['visits' => 100, 'opened' => 90, 'spend' => '2', 'valueReal' => '0'], 0.5, false, null, 'early'],
    'watch: −20 %' => [['visits' => 100, 'opened' => 90, 'spend' => '2', 'valueReal' => '1.6'], 0.5, true, null, 'watch'],
    'profit' => [['visits' => 200, 'opened' => 190, 'spend' => '1', 'valueReal' => '1.2'], 0.5, true, null, 'profit'],
    'weak start' => [['visits' => 60, 'opened' => 50, 'started' => 14, 'spend' => '0.5'], 0.5, false, TrafficBlockVerdict::WeakStart, 'weak_start'],
    'start fine' => [['visits' => 60, 'opened' => 50, 'started' => 15, 'spend' => '0.5'], 0.5, false, null, 'early'],
]);

it('calls a pop site bots only well below its own source’s average, and a banner below the lower of both shares', function (float $sourceOpenRate, array $rule, int $opened, ?TrafficBlockVerdict $verdict, float $below) {
    expect(judgedSite(['visits' => 40, 'opened' => $opened], $rule, openRate: $sourceOpenRate)['verdict'])->toBe($verdict)
        ->and(BlockJudge::openBelow(judgedBlockRule($rule), $sourceOpenRate))->toBe($below);
})->with([
    // Pop traffic opening 40% of the time: half of it, 20%, not the rule's 50%.
    'pop, 25% opened: an ordinary pop site' => [0.4, [], 10, null, 20.0],
    'pop, 20% opened: at the line, not under it' => [0.4, [], 8, null, 20.0],
    'pop, 17.5% opened: bots' => [0.4, [], 7, TrafficBlockVerdict::Bots, 20.0],
    // Banners opening 85% of the time: half of that, 42.5%, is the lower of both.
    'banner, 45% opened: under 50%, not under half the average' => [0.85, [], 18, null, 42.5],
    'banner, 40% opened: under both' => [0.85, [], 16, TrafficBlockVerdict::Bots, 42.5],
    // 70% of the banners' average is 59.5%: the rule's 50% is the lower one, and holds.
    'banner, 55% opened, held to 70% of the average: under it, not under 50%' => [0.85, ['block_open_below_average' => 70], 22, null, 50.0],
    'banner, 47.5% opened, held to 70% of the average: under both' => [0.85, ['block_open_below_average' => 70], 19, TrafficBlockVerdict::Bots, 50.0],
]);

it('holds a site to the rule’s share alone while its source has no clicks to average, and says which share it is held to', function () {
    $rule = judgedBlockRule();

    expect(BlockJudge::openBelow($rule, null))->toBe(50.0)
        ->and(BlockJudge::openBelowFromAverage($rule, null))->toBeFalse()
        ->and(BlockJudge::openBelowFromAverage($rule, 0.4))->toBeTrue()
        ->and(BlockJudge::openBelowFromAverage(judgedBlockRule(['block_open_below_average' => 70]), 0.85))->toBeFalse()
        ->and(BlockJudge::openBelowFromFloor($rule, null))->toBeFalse()
        ->and(BlockJudge::openBelowFromFloor($rule, 0.4))->toBeFalse()
        // A source none of whose clicks opened the page holds its sites to the floor alone, and to nothing with the floor off.
        ->and(BlockJudge::openBelow($rule, 0.0))->toBe(5.0)
        ->and(judgedSite(['visits' => 40, 'opened' => 0], openRate: 0.0)['verdict'])->toBe(TrafficBlockVerdict::Bots)
        ->and(judgedSite(['visits' => 40, 'opened' => 0], ['block_open_floor' => 0], openRate: 0.0)['verdict'])->toBeNull();
});

it('calls a site bots below the floor whatever its source’s average or the rule’s share, and says the floor is the line', function (?float $sourceOpenRate, array $rule, int $visits, int $opened, ?TrafficBlockVerdict $verdict, float $below, bool $fromFloor) {
    expect(judgedSite(['visits' => $visits, 'opened' => $opened], $rule, openRate: $sourceOpenRate)['verdict'])->toBe($verdict)
        ->and(BlockJudge::openBelow(judgedBlockRule($rule), $sourceOpenRate))->toBe($below)
        ->and(BlockJudge::openBelowFromFloor(judgedBlockRule($rule), $sourceOpenRate))->toBe($fromFloor)
        // The floor and the average never both claim the line.
        ->and(BlockJudge::openBelowFromAverage(judgedBlockRule($rule), $sourceOpenRate) && $fromFloor)->toBeFalse();
})->with([
    // A source that is nearly all bots opens the page 3% of the time: half of that, 1.5%, would hold nothing.
    'all-bots source averaging 3%, a site at 2.5%: under the floor' => [0.03, [], 40, 1, TrafficBlockVerdict::Bots, 5.0, true],
    'all-bots source averaging 3%, a site at 5%: at the floor, not under it' => [0.03, [], 40, 2, null, 5.0, true],
    'all-bots source averaging 3%, 29 clicks: too few for bots, floor or not' => [0.03, [], 29, 0, null, 5.0, true],
    // A source with a single site is its own average: it can never fall below half of itself.
    'single site, 2 of 50 opened, its source averaging the same 4%' => [0.04, [], 50, 2, TrafficBlockVerdict::Bots, 5.0, true],
    'single site, 2 of 50 opened, the floor off: never below half of itself' => [0.04, ['block_open_floor' => 0], 50, 2, null, 2.0, false],
    // Above the rule's own share the floor lifts the line past it, average or none.
    'floor 60% above the rule’s 50%, a banner site at 55%' => [0.85, ['block_open_floor' => 60], 40, 22, TrafficBlockVerdict::Bots, 60.0, true],
    'floor 60% above the rule’s 50%, no average yet, a site at 57.5%' => [null, ['block_open_floor' => 60], 40, 23, TrafficBlockVerdict::Bots, 60.0, true],
    'floor 60% above the rule’s 50%, a site at 60%: at the line' => [null, ['block_open_floor' => 60], 40, 24, null, 60.0, true],
    // An ordinary pop source: half its 40% average, 20%, stands above the floor and stays the line.
    'pop source averaging 40%, the floor below half of it' => [0.4, [], 40, 7, TrafficBlockVerdict::Bots, 20.0, false],
    // A source's own floor stands in for the general one.
    'own floor 10%, all-bots source averaging 3%, a site at 7.5%' => [0.03, ['block_open_floor' => 10], 40, 3, TrafficBlockVerdict::Bots, 10.0, true],
    'own floor 2%, all-bots source averaging 3%, a site at 2.5%: above it' => [0.03, ['block_open_floor' => 2], 40, 1, null, 2.0, true],
    'own floor 0, the general 5% kept off for this source' => [0.03, ['block_open_floor' => 0], 40, 1, null, 1.5, false],
]);

it('calls a site losing only once the days after its first click have passed, and never holds back bots or a profit', function (array $figures, string $firstClick, array $rule, ?TrafficBlockVerdict $verdict, string $state) {
    expect(judgedSite($figures, $rule, worthKnown: true, firstClick: $firstClick))->toBe(['verdict' => $verdict, 'state' => $state]);
})->with([
    'losing, two days after its first click: waiting' => [['visits' => 100, 'opened' => 90, 'spend' => '2', 'valueReal' => '1'], '2026-11-16 12:00:00', [], null, 'waiting'],
    'losing, three days after: losing' => [['visits' => 100, 'opened' => 90, 'spend' => '2', 'valueReal' => '1'], '2026-11-15 12:00:00', [], TrafficBlockVerdict::Losing, 'losing'],
    'losing, a day after, the source waiting none' => [['visits' => 100, 'opened' => 90, 'spend' => '2', 'valueReal' => '1'], '2026-11-17 12:00:00', ['block_grace_days' => 0], TrafficBlockVerdict::Losing, 'losing'],
    'losing, a week after, the source waiting ten days' => [['visits' => 100, 'opened' => 90, 'spend' => '2', 'valueReal' => '1'], '2026-11-11 12:00:00', ['block_grace_days' => 10], null, 'waiting'],
    'bots an hour after its first click' => [['visits' => 40, 'opened' => 2, 'spend' => '2', 'valueReal' => '1'], '2026-11-18 11:00:00', [], TrafficBlockVerdict::Bots, 'bots'],
    'profit a day after its first click' => [['visits' => 200, 'opened' => 190, 'spend' => '1', 'valueReal' => '1.2'], '2026-11-17 12:00:00', [], null, 'profit'],
]);

it('says from when a site may be called losing', function () {
    expect(BlockJudge::judgedFrom(judgedBlockRule(), CarbonImmutable::parse('2026-11-16 09:30:00'))?->toDateTimeString())->toBe('2026-11-19 09:30:00')
        ->and(BlockJudge::judgedFrom(judgedBlockRule(), null))->toBeNull()
        // Clicked on the window's first day: there before it opened, so no wait.
        ->and(BlockJudge::judgedFrom(judgedBlockRule(['block_grace_days' => 30]), CarbonImmutable::parse('2026-10-20 23:00:00'), CarbonImmutable::parse('2026-10-20')))->toBeNull()
        ->and(BlockJudge::judgedFrom(judgedBlockRule(['block_grace_days' => 30]), CarbonImmutable::parse('2026-10-21 00:00:00'), CarbonImmutable::parse('2026-10-20'))?->toDateTimeString())->toBe('2026-11-20 00:00:00');
});

it('never keeps a site clicked since the window opened waiting, however many days the rule waits', function (string $firstClick, array $rule, ?TrafficBlockVerdict $verdict, string $state) {
    // The rule's 30 days on 18 Nov run from 20 Oct.
    expect(judgedSite(['visits' => 100, 'opened' => 90, 'spend' => '2', 'valueReal' => '1'], $rule, worthKnown: true, firstClick: $firstClick, windowFrom: '2026-10-20'))->toBe(['verdict' => $verdict, 'state' => $state]);
})->with([
    'clicked from the window’s first hour, the rule waiting 30 days: losing' => ['2026-10-20 00:00:00', ['block_grace_days' => 30], TrafficBlockVerdict::Losing, 'losing'],
    'clicked from the window’s first day, a network day behind UTC: losing' => ['2026-10-20 05:00:00', ['block_grace_days' => 30], TrafficBlockVerdict::Losing, 'losing'],
    'clicked from the evening before, a network day ahead of UTC: losing' => ['2026-10-19 14:00:00', ['block_grace_days' => 30], TrafficBlockVerdict::Losing, 'losing'],
    'first clicked on the window’s second day, the rule waiting 30 days: waiting' => ['2026-10-21 00:00:00', ['block_grace_days' => 30], null, 'waiting'],
    'first clicked two days ago: waiting, the window or not' => ['2026-11-16 12:00:00', [], null, 'waiting'],
]);

it('waits for so many times the target price of an account where there is one, else for the rule’s spend or clicks', function (array $figures, ?string $target, array $rule, ?float $startRate, ?TrafficBlockVerdict $verdict, string $state) {
    expect(judgedSite($figures, $rule, $startRate, worthKnown: true, target: $target))->toBe(['verdict' => $verdict, 'state' => $state]);
})->with([
    // A target of $0.60: judged from $1.20, well before the rule's $2 or 200 clicks.
    'target $0.60, $1.00 spent: too early' => [['visits' => 50, 'opened' => 45, 'spend' => '1', 'valueReal' => '0.3'], '0.60', [], null, null, 'early'],
    'target $0.60, $1.20 spent at −50%: losing' => [['visits' => 60, 'opened' => 55, 'spend' => '1.2', 'valueReal' => '0.6'], '0.60', [], null, TrafficBlockVerdict::Losing, 'losing'],
    'target $0.60, three times it: $1.20 is too early' => [['visits' => 60, 'opened' => 55, 'spend' => '1.2', 'valueReal' => '0.6'], '0.60', ['block_target_multiple' => '3'], null, null, 'early'],
    // A target of $2: judged from $4, however far past the rule's $2 or 200 clicks.
    'target $2, $3 spent and 300 clicks: too early' => [['visits' => 300, 'opened' => 280, 'spend' => '3', 'valueReal' => '0.5'], '2', [], null, null, 'early'],
    'target $2, $4 spent at −60%: losing' => [['visits' => 400, 'opened' => 380, 'spend' => '4', 'valueReal' => '1.6'], '2', [], null, TrafficBlockVerdict::Losing, 'losing'],
    'no target, $2 spent at −50%: losing by the rule’s spend' => [['visits' => 100, 'opened' => 90, 'spend' => '2', 'valueReal' => '1'], null, [], null, TrafficBlockVerdict::Losing, 'losing'],
    'a target of nothing counts as none' => [['visits' => 100, 'opened' => 90, 'spend' => '2', 'valueReal' => '1'], '0', [], null, TrafficBlockVerdict::Losing, 'losing'],
    // A weak start shows below the rule's spend and clicks, the target reached or not.
    'target not reached, a weak start' => [['visits' => 60, 'opened' => 50, 'started' => 14, 'spend' => '0.5'], '0.60', [], 0.5, TrafficBlockVerdict::WeakStart, 'weak_start'],
]);

it('takes a site’s target price of an account from its campaign, else its source', function () {
    expect(BlockRule::target('0.70', '0.60'))->toBe(['amount' => '0.70000000', 'from' => 'campaign'])
        ->and(BlockRule::target(null, '0.60'))->toBe(['amount' => '0.60000000', 'from' => 'source'])
        ->and(BlockRule::target('0', '0.60'))->toBe(['amount' => '0.60000000', 'from' => 'source'])
        ->and(BlockRule::target('', null))->toBeNull()
        ->and(BlockJudge::spendNeeded(judgedBlockRule(), '0.60')?->isEqualTo('1.2'))->toBeTrue()
        ->and(BlockJudge::spendNeeded(judgedBlockRule(['block_target_multiple' => '1.5']), '0.60')?->isEqualTo('0.9'))->toBeTrue()
        ->and(BlockJudge::spendNeeded(judgedBlockRule(), null))->toBeNull();
});

it('judges a source by its own thresholds where it set them, the general ones elsewhere', function () {
    $own = ['block_bot_clicks' => 45, 'block_open_below' => null, 'block_min_spend' => '3.5', 'block_target_multiple' => '', 'block_roi_below' => -25];

    expect(judgedBlockRule($own))->toMatchArray(['block_bot_clicks' => 45, 'block_open_below' => 50, 'block_min_spend' => '3.50', 'block_target_multiple' => '2.0', 'block_roi_below' => -25, 'block_grace_days' => 3])
        ->and(BlockRule::own($own))->toBe(['block_bot_clicks', 'block_min_spend', 'block_roi_below'])
        ->and(BlockRule::stored('block_min_spend', '3.5'))->toBe('3.50')
        ->and(BlockRule::stored('block_target_multiple', '1.5'))->toBe('1.5')
        ->and(BlockRule::stored('block_grace_days', '0'))->toBe(0)
        ->and(BlockRule::stored('block_bot_clicks', ''))->toBeNull()
        // 40 clicks, 20 opened: bots only for the source that waits for fewer clicks and holds sites to a higher share.
        ->and(judgedSite(['visits' => 40, 'opened' => 20])['verdict'])->toBeNull()
        ->and(judgedSite(['visits' => 40, 'opened' => 20], ['block_open_below' => 60])['verdict'])->toBe(TrafficBlockVerdict::Bots)
        ->and(judgedSite(['visits' => 40, 'opened' => 20], ['block_open_below' => 60, 'block_bot_clicks' => 45])['verdict'])->toBeNull();
});
