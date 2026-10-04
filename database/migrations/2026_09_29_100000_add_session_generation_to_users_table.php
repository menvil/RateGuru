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
        Schema::table('users', function (Blueprint $table) {
            // Every session remembers the value this column had when it signed
            // in. Changing it ends every other session of the account on its
            // next request, whatever the session driver — which deleting
            // session rows alone only achieves for the database driver. NULL,
            // the value every account starts with, changes nothing.
            $table->string('session_generation', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_generation');
        });
    }
};
