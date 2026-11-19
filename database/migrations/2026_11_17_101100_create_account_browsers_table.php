<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The browsers an account signed up or signed in with, by the hash of
        // their rg_game_visitor token: a visit from one of them is a known
        // visitor, worth nothing new and never reported to a network.
        Schema::create('account_browsers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('visitor_hash', 64);
            $table->dateTime('linked_at');

            $table->unique(['visitor_hash', 'user_id'], 'account_browsers_hash_user_unique');
            $table->index('user_id', 'account_browsers_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_browsers');
    }
};
