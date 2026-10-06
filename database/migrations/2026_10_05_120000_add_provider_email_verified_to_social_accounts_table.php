<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the provider had CONFIRMED the address in `provider_email` at the
 * moment this row last recorded it.
 *
 * It exists because account claiming needs to know, and until now could only
 * guess. A claim by somebody who has proved an address has to decide what to do
 * with the sign-in methods already on that account, and the only thing it could
 * look at was whether the account had a password — which says nothing about
 * whether the holder of an existing provider link ever owned the address.
 *
 * Three states, and the third is the point:
 *
 *   true   the provider confirmed the current provider_email
 *   false  the provider explicitly did not confirm it
 *   null   a row written before this column existed, so there is no proof
 *
 * Existing rows stay NULL. They are NOT backfilled to true from the provider
 * type or any other heuristic: Facebook vouching for every address it reports
 * is true of the rule applied today, not evidence about a row written yesterday,
 * and a claim treating a guess as proof is exactly the defect this closes.
 * Untrusted-by-default costs the person one re-link; the opposite costs them
 * their account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->boolean('provider_email_verified')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn('provider_email_verified');
        });
    }
};
