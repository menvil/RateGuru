<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One working-out of the chances (traffic:work-out-worth): from which
        // matured visits, at which prices, and how long after the click
        // accounts are confirmed. The last 30 are kept.
        Schema::create('traffic_chance_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 12);
            $table->string('reason', 16);
            $table->foreignId('price_set_id')->constrained('traffic_price_sets')->restrictOnDelete();
            $table->dateTime('matured_before');
            $table->unsignedInteger('visits_scanned')->default(0);
            $table->json('confirmation_curve')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('error', 300)->nullable();

            $table->index('price_set_id', 'traffic_chance_runs_price_set_index');
            $table->index(['status', 'id'], 'traffic_chance_runs_status_index');
        });

        // The chance that a new visitor who reached a step ends up a confirmed
        // account, for one slice — game, source, device, country group, any
        // of them left open — leaning on the slice above while its own
        // accounts are few.
        Schema::create('traffic_step_chances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('traffic_chance_runs')->cascadeOnDelete();
            $table->string('slice_key', 120);
            $table->unsignedBigInteger('game_id')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('channel', 16)->nullable();
            $table->string('device', 8)->nullable();
            $table->string('country_group', 24)->nullable();
            $table->string('step', 24);
            $table->unsignedInteger('visitors');
            $table->unsignedInteger('accounts');
            $table->unsignedInteger('slice_accounts');
            $table->decimal('chance', 10, 8);
            $table->boolean('thin');
            $table->string('stands_on', 120);

            $table->unique(['run_id', 'slice_key', 'step'], 'traffic_step_chances_slice_step_unique');
            $table->index(['run_id', 'game_id', 'step'], 'traffic_step_chances_game_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_step_chances');
        Schema::dropIfExists('traffic_chance_runs');
    }
};
