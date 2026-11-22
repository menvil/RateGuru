<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const array COLUMNS = ['credited_source_key', 'credited_device', 'credited_game_id'];

    public function up(): void
    {
        // What Analytics → Retention groups and filters an account by, copied
        // onto its attribution as it is credited (RecordAccountSignupAction):
        // the source key of the visit it is credited to — the key of the
        // session that visit belonged to — that visit's device, and the game
        // of the attempt it is credited to. Retention reads the accounts and
        // their days alone, never the visits. The visit's two outlive it: a
        // visit purged after 400 days lets credited_visit_id go and leaves
        // them. The game goes with the attempt's pointer. No foreign key: a
        // copy, and games are archived, never deleted. Nullable columns
        // alone, added in place on every engine.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('account_attributions', function (Blueprint $table) {
            $table->string('credited_source_key', 140)->nullable();
            $table->string('credited_device', 8)->nullable();
            $table->unsignedBigInteger('credited_game_id')->nullable();
        });

        // The accounts credited before: copied from the visits and attempts
        // they name, a thousand at a time, each set of values written with
        // one statement. An account credited to no visit, or to one already
        // purged — whose pointer the purge let go — has no source or device,
        // as Retention read it before.
        DB::table('account_attributions')
            ->where(fn ($query) => $query->whereNotNull('credited_visit_id')->orWhereNotNull('credited_attempt_id'))
            ->select(['id', 'credited_visit_id', 'credited_attempt_id'])
            ->chunkById(1000, function ($attributions): void {
                $visits = DB::table('traffic_visits')
                    ->whereIn('id', $attributions->pluck('credited_visit_id')->filter()->unique()->values()->all())
                    ->get(['id', 'source_key', 'device'])
                    ->keyBy('id');
                $games = DB::table('game_attempts')
                    ->whereIn('id', $attributions->pluck('credited_attempt_id')->filter()->unique()->values()->all())
                    ->pluck('game_id', 'id');
                $copies = [];

                foreach ($attributions as $attribution) {
                    $visit = $attribution->credited_visit_id === null ? null : $visits->get($attribution->credited_visit_id);
                    $game = $attribution->credited_attempt_id === null ? null : $games->get($attribution->credited_attempt_id);
                    $values = [
                        'credited_source_key' => $visit?->source_key,
                        'credited_device' => $visit?->device,
                        'credited_game_id' => $game === null ? null : (int) $game,
                    ];

                    $copies[json_encode($values, JSON_THROW_ON_ERROR)][] = $attribution->id;
                }

                foreach ($copies as $values => $ids) {
                    DB::table('account_attributions')->whereIn('id', $ids)->update(json_decode($values, true, flags: JSON_THROW_ON_ERROR));
                }
            });
    }

    public function down(): void
    {
        Schema::table('account_attributions', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
