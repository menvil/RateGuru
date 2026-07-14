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
        Schema::table('cuisine_votes', function (Blueprint $table) {
            $table->unique(['post_id', 'user_id']);
        });
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('cuisine_votes', function (Blueprint $table) {
                $table->dropForeign(['post_id']);
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table('cuisine_votes', function (Blueprint $table) {
            $table->dropUnique(['post_id', 'user_id']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('cuisine_votes', function (Blueprint $table) {
                $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
