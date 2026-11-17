<?php

namespace App\Filament\Resources\Games\Pages;

use App\Actions\Traffic\SetTrafficStepValueAction;
use App\Enums\TrafficStepValueMode;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameResource;
use App\Models\GameVersion;
use App\Models\TrafficPriceSet;
use App\Models\TrafficStepChance;
use App\Models\TrafficStepValue;
use App\Queries\Traffic\TrafficWorthQuery;
use App\Support\Traffic\Worth\Slice;
use App\Support\Traffic\Worth\StepWorth;
use App\Support\Traffic\Worth\TrafficStep;
use App\Support\Traffic\Worth\WorthPrices;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;

/**
 * A game's Value tab: what each step of a play is worth — the chance that a
 * new visitor who reached it becomes a confirmed account, from the last
 * working-out, times what an account is worth — from opening the page,
 * through every round, to what follows the result and the site after it,
 * with the value the administrator sets by hand where the data is thin or
 * they know better. A value set here counts from the next postback, with no
 * new version.
 */
final class GameValue extends Page
{
    use InteractsWithGameEditor;

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-value';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    /** @var array<string, array{value: string, mode: string}> what is typed for each step */
    public array $manual = [];

    public function mount(int|string $record): void
    {
        $game = $this->mountGame($record);
        $this->manual = TrafficStepValue::query()->where('game_id', $game->id)->get()
            ->mapWithKeys(fn (TrafficStepValue $value): array => [$value->step => ['value' => (string) $value->value, 'mode' => $value->mode->value]])->all();
    }

    public function setValue(mixed $step): void
    {
        $game = $this->game();

        if ($game === null || ! is_string($step)) {
            return;
        }

        $typed = $this->manual[$step] ?? ['value' => '', 'mode' => TrafficStepValueMode::Thin->value];

        try {
            app(SetTrafficStepValueAction::class)->set($this->admin(), $game, $step, $typed['value'], TrafficStepValueMode::tryFrom($typed['mode']) ?? TrafficStepValueMode::Thin);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->toast('Value set. It counts from the next postback.');
    }

    public function clearValue(mixed $step): void
    {
        $game = $this->game();

        if ($game === null || ! is_string($step)) {
            return;
        }

        try {
            app(SetTrafficStepValueAction::class)->clear($this->admin(), $game, $step);
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        unset($this->manual[$step]);
        $this->toast('Value removed');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $game = $this->game();
        abort_if($game === null, 404);

        $worth = app(TrafficWorthQuery::class);
        $run = $worth->lastRun();
        $slice = new Slice((int) $game->id);
        $table = $worth->table($run, $slice);
        $set = TrafficPriceSet::current();
        $prices = new WorthPrices((string) $set->account_price, (string) $set->post_bonus, (string) $set->returned_bonus);
        $rounds = max(1, (int) GameVersion::query()->where('game_id', $game->id)->max('round_count'));
        $stored = $worth->manualValues((int) $game->id);
        $reached = $run === null ? collect() : TrafficStepChance::query()->where('run_id', $run->id)->where('slice_key', $slice->key())->pluck('visitors', 'step');
        $opened = (int) ($reached['opened'] ?? 0);
        $halfway = TrafficStep::halfway($rounds);
        $goal = GameVersion::query()->where('game_id', $game->id)->orderByDesc('number')->first()?->flowGoal();

        $steps = array_map(function (string $step) use ($slice, $prices, $stored, $table, $reached, $opened, $halfway, $goal): array {
            $result = StepWorth::of($slice, $step, $prices, 100, false, $stored[$step] ?? null, $table);

            return [
                'step' => $step,
                'label' => ucfirst(str_replace('_', ' ', $step)),
                'reached' => $opened > 0 && isset($reached[$step]) ? $reached[$step] / $opened : null,
                'chance' => $result['chance'],
                'worth' => (string) $result['value'],
                'basis' => $result['basis']->value,
                'tags' => array_values(array_filter([
                    $step === $halfway ? 'Halfway' : null,
                    $goal !== null && $goal->step() === $step ? 'Goal of this game' : null,
                    isset($stored[$step]) ? 'Set by hand' : null,
                ])),
                'manual' => in_array($step, ['account', 'login'], true) ? null : $stored[$step] ?? null,
            ];
        }, TrafficStep::all($rounds));

        return [
            'header' => $this->editorHeader($game, 'value'),
            'steps' => $steps,
            'run' => $run,
            'accountWorth' => (string) $prices->account(0, 0, 100),
        ];
    }
}
