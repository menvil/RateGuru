<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->index(['status', 'published_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('hot_score');
            $table->index('reports_count');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['status', 'published_at']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['hot_score']);
            $table->dropIndex(['reports_count']);
        });
    }
};
