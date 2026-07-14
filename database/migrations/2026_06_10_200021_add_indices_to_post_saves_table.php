<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('post_saves', function (Blueprint $table) {
            $table->index(['user_id', 'created_at']);
            $table->index('post_id');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('post_saves', function (Blueprint $table) {
                $table->dropForeign(['post_id']);
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table('post_saves', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['post_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('post_saves', function (Blueprint $table) {
                $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
