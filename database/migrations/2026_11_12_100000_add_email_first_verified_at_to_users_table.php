<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the account first confirmed an address: unlike email_verified_at,
 * which a change of address clears, this one is never cleared again. The
 * cleanup of accounts that never confirmed their address reads it, so an
 * established account that has just changed its address is not taken for one.
 *
 * Accounts confirmed today take the time they were confirmed. One that
 * confirmed an address once and then changed it unconfirmed cannot be told
 * apart any more; it counts as never confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_first_verified_at')->nullable()->after('email_verified_at');
        });

        DB::table('users')
            ->whereNotNull('email_verified_at')
            ->update(['email_first_verified_at' => DB::raw('email_verified_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_first_verified_at');
        });
    }
};
