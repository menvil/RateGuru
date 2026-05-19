<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->unique(
                ['reporter_id', 'target_type', 'target_id'],
                'reports_reporter_target_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropUnique('reports_reporter_target_unique');
        });
    }
};
