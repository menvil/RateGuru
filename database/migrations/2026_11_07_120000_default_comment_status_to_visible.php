<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The column was created defaulting to 'published', a status comments
     * never had: CommentStatus is visible or hidden, so a row written without
     * a status could not be read back at all. It now defaults to what a new
     * comment is, and any row the old default produced becomes that.
     */
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->string('status')->default('visible')->change();
        });

        DB::table('comments')->where('status', 'published')->update(['status' => 'visible']);
    }

    public function down(): void
    {
        // Only the default goes back: a row this migration made visible was
        // unreadable before, and is not made unreadable again.
        Schema::table('comments', function (Blueprint $table) {
            $table->string('status')->default('published')->change();
        });
    }
};
