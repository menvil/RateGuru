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

        Schema::table('traffic_visits', function (Blueprint $table) {
            // The network the visit's address belongs to, by the local networks' database (DB-IP ASN Lite): its
            // autonomous system number, never the address. Empty while that database is missing, and for every
            // visit recorded before it came. A big integer: a 4-byte ASN reaches 4294967295, past PostgreSQL's integer.
            $table->unsignedBigInteger('asn')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dropColumn('asn');
        });
    }
};
