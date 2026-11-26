<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('users', function (Blueprint $table) {
            // Where the account was made from, by the request's address (RecordMemberPlaceAction): the country and city
            // the local IP database places it in, a one-way hash of it (IpAddressHash) that finds the accounts made or
            // visited from the same one, and the address itself only while Project settings keep IP addresses.
            $table->string('signup_ip', 45)->nullable();
            $table->char('signup_ip_hash', 64)->nullable();
            $table->char('signup_country', 2)->nullable();
            $table->string('signup_city', 80)->nullable();
            // The same of the member's latest visit, and when it started: a sign-in, or a return after more than an
            // hour away (TouchMemberVisitAction).
            $table->dateTime('visit_started_at')->nullable();
            $table->string('visit_ip', 45)->nullable();
            $table->char('visit_ip_hash', 64)->nullable();
            $table->char('visit_country', 2)->nullable();
            $table->string('visit_city', 80)->nullable();

            $table->index('signup_ip_hash', 'users_signup_ip_hash_index');
            $table->index('visit_ip_hash', 'users_visit_ip_hash_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_signup_ip_hash_index');
            $table->dropIndex('users_visit_ip_hash_index');
            $table->dropColumn(['signup_ip', 'signup_ip_hash', 'signup_country', 'signup_city', 'visit_started_at', 'visit_ip', 'visit_ip_hash', 'visit_country', 'visit_city']);
        });
    }
};
