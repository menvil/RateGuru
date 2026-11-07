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
        Schema::table('social_accounts', function (Blueprint $table) {
            // The address of the Google or Facebook account itself, shown on
            // the profile so a person with several accounts at a provider can
            // tell which one is connected. It may differ from the RateGuru
            // email, is refreshed on every sign-in through the identity, and
            // is deleted with the row when the account is anonymized. Never
            // used to find an account: the identity is provider + subject.
            $table->string('provider_email')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('provider_email');
        });
    }
};
