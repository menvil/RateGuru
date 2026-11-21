<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A short number for each browser whose page ran, kept as long as any
        // of its visits is: a day's people are a set of these numbers
        // (analytics_cells). Only ever added to — a browser given a second
        // number would be two people in a union of days — and a row goes only
        // once no row of analytics_visits names the browser any more.
        Schema::create('analytics_pids', function (Blueprint $table) {
            $table->bigInteger('visitor_key')->primary();
            $table->unsignedInteger('pid')->unique();
        });

        // The people of a network day per level of detail (AnalyticsCellLevel):
        // for every device, source and device, source, campaign and device,
        // or country and device, the set of each step's people as a roaring
        // bitmap of their numbers, and the visits' sums. A period's people
        // are the union of its days' sets: no visit of it is read again. Only
        // the visits whose page ran count, as on the tabs; a column outside
        // the row's level is empty, and inside it an empty one is a visit of
        // no value. Written only on PostgreSQL with the roaringbitmap
        // extension (RefreshAnalyticsCellsAction); elsewhere it stays empty
        // and the tabs read the visits.
        Schema::create('analytics_cells', function (Blueprint $table) {
            $table->id();
            $table->string('level', 32);
            $table->char('network_day', 10);
            $table->string('device', 8)->nullable();
            $table->string('source_key', 140)->nullable();
            $table->string('campaign_key', 140)->nullable();
            $table->char('country', 2)->nullable();

            foreach (['visitors', 'new_visitors', 'starters', 'completers', 'goal', 'signup_form', 'signups', 'accounts', 'activated', 'voters', 'commenters'] as $people) {
                $table->binary($people)->nullable();
            }

            $table->unsignedBigInteger('visits')->default(0);
            $table->unsignedBigInteger('tagged')->default(0);
            $table->unsignedBigInteger('votes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('posts')->default(0);
            $table->decimal('spend', 18, 8)->default(0);
            $table->decimal('spend_estimated', 18, 8)->default(0);

            $table->index(['level', 'network_day'], 'analytics_cells_level_day_index');
            $table->index('network_day', 'analytics_cells_day_index');
        });

        // The accounts of the same cells by the UTC day they signed up on:
        // whether an account's days to activate are over is a matter of that
        // day, which need not be the visit's.
        Schema::create('analytics_cell_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('level', 32);
            $table->char('network_day', 10);
            $table->string('device', 8)->nullable();
            $table->string('source_key', 140)->nullable();
            $table->string('campaign_key', 140)->nullable();
            $table->char('country', 2)->nullable();
            $table->char('signup_day', 10);
            $table->binary('accounts')->nullable();

            $table->index(['level', 'network_day'], 'analytics_cell_accounts_level_day_index');
            $table->index('network_day', 'analytics_cell_accounts_day_index');
        });

        // How far the cells are: the day they were last filled up to on the
        // way to every day, and when every day first was.
        Schema::table('analytics_rollup_states', function (Blueprint $table) {
            $table->char('cells_through', 10)->nullable();
            $table->dateTime('cells_filled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('analytics_rollup_states', function (Blueprint $table) {
            $table->dropColumn(['cells_through', 'cells_filled_at']);
        });

        Schema::dropIfExists('analytics_cell_accounts');
        Schema::dropIfExists('analytics_cells');
        Schema::dropIfExists('analytics_pids');
    }
};
