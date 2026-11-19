<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\TrafficStepValueMode;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\Game;
use App\Models\TrafficStepValue;
use App\Models\User;
use App\Queries\Traffic\GameWorthQuery;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Worth\TrafficStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The administrator's own value for a step of a game — always, or only while
 * the data behind the step is thin — or its removal. It counts from the next
 * postback at once, with no new version; the visits still open take it on
 * the next night. An account and a sign-in have no value to set here: both
 * are priced on Traffic sources → Value, for the whole site, an account
 * also per paid source.
 */
final class SetTrafficStepValueAction
{
    use LocksTrafficRecords;

    public function __construct(private readonly GameWorthQuery $worth) {}

    /** @throws CannotManageTrafficException|ValidationException */
    public function set(User $admin, Game $game, string $step, mixed $value, TrafficStepValueMode $mode, ?string $note = null): TrafficStepValue
    {
        // The steps as the Value tab lays them out: for a game not yet published, by its first content set's rounds.
        $steps = array_values(array_diff(TrafficStep::all($this->worth->rounds($game)), ['account', 'login']));
        Validator::make(['step' => $step, 'value' => $value, 'note' => $note], [
            'step' => ['required', Rule::in($steps)],
            'value' => ['required', 'numeric', 'regex:/^\d{1,10}(\.\d{1,8})?$/', 'min:0', 'max:1000'],
            'note' => ['nullable', 'string', 'max:200'],
        ])->validate();

        return DB::transaction(function () use ($admin, $game, $step, $value, $mode, $note): TrafficStepValue {
            $locked = $this->lockAdministrator($admin);

            return TrafficStepValue::query()->updateOrCreate(['game_id' => $game->id, 'step' => $step], [
                'value' => TrafficMoney::stored($value, 4),
                'mode' => $mode,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'set_by_user_id' => $locked->id,
            ]);
        });
    }

    /** @throws CannotManageTrafficException */
    public function clear(User $admin, Game $game, string $step): void
    {
        DB::transaction(function () use ($admin, $game, $step): void {
            $this->lockAdministrator($admin);
            TrafficStepValue::query()->where('game_id', $game->id)->where('step', $step)->delete();
        });
    }
}
