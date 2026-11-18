<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the outcomes of a visit are worth — a confirmed account, the
        // bonus for a first published post and for coming back on another
        // day, and a sign-in to an account the visitor already had — with the
        // country groups and the share of the price each is worth. Never
        // changed once written: saving prices or groups adds a set, the newest
        // one is in force, and every visit keeps the set that was in force
        // when it came. A paid source can have an account price of its own in
        // a set, so an account from it is worth that instead of the site's.
        Schema::create('traffic_price_sets', function (Blueprint $table) {
            $table->id();
            $table->decimal('account_price', 10, 4);
            $table->decimal('post_bonus', 10, 4);
            $table->decimal('returned_bonus', 10, 4);
            $table->decimal('login_price', 10, 4)->default(0);
            // [{key, name, percent, countries: [upper-case codes]}], the last
            // group holding every country the others do not name.
            $table->json('country_groups');
            // {source id: price with four decimals} for the paid sources whose
            // account is worth its own price; a source not named, or the whole
            // column null, counts the site's account price.
            $table->json('source_account_prices')->nullable();
            $table->string('summary', 300)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at');

            $table->index('created_by_user_id', 'traffic_price_sets_created_by_index');
        });

        DB::table('traffic_price_sets')->insert([
            'account_price' => '0.5000',
            'post_bonus' => '1.0000',
            'returned_bonus' => '0.2000',
            'login_price' => '0.0000',
            'country_groups' => json_encode([
                ['key' => 't1', 'name' => 'Tier 1', 'percent' => 100, 'countries' => ['US', 'GB', 'CA', 'AU', 'DE', 'AT', 'CH', 'NL']],
                ['key' => 't2', 'name' => 'Tier 2', 'percent' => 60, 'countries' => ['ES', 'FR', 'IT', 'PL']],
                ['key' => 'rest', 'name' => 'Every other country', 'percent' => 30, 'countries' => []],
            ], JSON_THROW_ON_ERROR),
            'summary' => 'Starting prices',
            'created_by_user_id' => null,
            'created_at' => CarbonImmutable::now('UTC'),
        ]);

        // The tracker's own settings, one row: the block rule, the IP
        // database edition, and the two switches that start it — both off,
        // so a release records and sends nothing until they are switched on.
        Schema::create('traffic_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('block_min_spend', 10, 2)->default(2);
            $table->unsignedInteger('block_min_clicks')->default(200);
            $table->smallInteger('block_roi_below')->default(-40);
            $table->unsignedTinyInteger('block_open_below')->default(50);
            $table->unsignedInteger('block_bot_clicks')->default(30);
            $table->unsignedInteger('block_start_clicks')->default(60);
            $table->unsignedTinyInteger('block_start_percent')->default(60);
            $table->string('geo_edition', 24)->default('geolite2_city');
            $table->dateTime('geo_refresh_requested_at')->nullable();
            $table->boolean('capture_enabled')->default(false);
            $table->boolean('postbacks_enabled')->default(false);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index('updated_by_user_id', 'traffic_settings_updated_by_index');
        });

        // A step of a game whose worth the administrator set by hand, always
        // or only while there is too little data to work it out.
        Schema::create('traffic_step_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
            $table->string('step', 24);
            $table->decimal('value', 10, 4);
            // A growing set (TrafficStepValueMode).
            $table->string('mode', 8);
            $table->string('note', 300)->nullable();
            $table->foreignId('set_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['game_id', 'step'], 'traffic_step_values_game_step_unique');
            $table->index('set_by_user_id', 'traffic_step_values_set_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_step_values');
        Schema::dropIfExists('traffic_settings');
        Schema::dropIfExists('traffic_price_sets');
    }
};
