<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_audit_runs', function (Blueprint $table) {
            // Assets released but still referenced: a warning like the other
            // counted findings, so the health a run shows takes it into account.
            $table->unsignedInteger('released_but_referenced')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('media_audit_runs', function (Blueprint $table) {
            $table->dropColumn('released_but_referenced');
        });
    }
};
