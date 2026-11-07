<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_plans', function (Blueprint $table) {
            // When an administrator stopped the plan. Stop cancels what has
            // not started, and a run already under way is left to finish —
            // but one the rate limit sends back to wait is cancelled too,
            // never queued again: the plan was stopped.
            $table->timestamp('stopped_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('studio_plans', function (Blueprint $table) {
            $table->dropColumn('stopped_at');
        });
    }
};
