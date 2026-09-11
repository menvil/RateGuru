<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // An account created through Google or Facebook has no password at
            // all, and NULL is the honest representation of that. Nothing is
            // weakened: the auth boundary already treats a missing hash as "no
            // credential" and never matches it, and password reset remains the
            // one way to obtain a password later.
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rolling back must not fail on the social-only accounts created while
        // the column was nullable, and must not hand them a usable credential
        // either: each gets its own random, unrecoverable hash — the technique
        // account anonymization uses — so password reset stays the only way in.
        DB::table('users')
            ->whereNull('password')
            ->orderBy('id')
            ->chunkById(500, function (iterable $rows): void {
                foreach ($rows as $row) {
                    DB::table('users')
                        ->where('id', $row->id)
                        ->update(['password' => Hash::make(Str::random(64))]);
                }
            });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
