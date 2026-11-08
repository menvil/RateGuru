<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A visitor is either a signed-in person or an anonymous browser, never both. */
    private const IDENTITY_CHECK = '(user_id IS NOT NULL AND visitor_hash IS NULL) OR (user_id IS NULL AND visitor_hash IS NOT NULL)';

    public function up(): void
    {
        // Which version a visitor of an audience was given, so they keep it
        // when they come back. `active_key` names the visitor while the
        // assignment is open and is cleared when it ends: the unique index
        // then allows one open assignment per visitor and any number of ended
        // ones, on every engine (unique indexes let NULLs repeat).
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(sprintf(<<<'SQL'
                CREATE TABLE "game_assignments" (
                    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    "game_id" INTEGER NOT NULL,
                    "audience" VARCHAR NOT NULL CHECK ("audience" IN ('anonymous', 'authenticated')),
                    "user_id" INTEGER,
                    "visitor_hash" VARCHAR,
                    "active_key" VARCHAR,
                    "version_id" INTEGER NOT NULL,
                    "period_id" INTEGER NOT NULL,
                    "assigned_at" DATETIME NOT NULL,
                    "last_seen_at" DATETIME NOT NULL,
                    "ended_at" DATETIME,
                    "created_at" DATETIME,
                    "updated_at" DATETIME,
                    FOREIGN KEY ("game_id") REFERENCES "games" ("id") ON DELETE RESTRICT,
                    FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE RESTRICT,
                    FOREIGN KEY ("version_id") REFERENCES "game_versions" ("id") ON DELETE RESTRICT,
                    FOREIGN KEY ("period_id") REFERENCES "game_traffic_periods" ("id") ON DELETE RESTRICT,
                    CONSTRAINT "game_assignments_identity_check" CHECK (%s)
                )
                SQL, self::IDENTITY_CHECK));
        } else {
            Schema::create('game_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
                $table->enum('audience', ['anonymous', 'authenticated']);
                $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
                // The SHA-256 of the anonymous visitor's cookie token; the
                // token itself is never stored.
                $table->char('visitor_hash', 64)->nullable();
                $table->string('active_key', 80)->nullable();
                $table->foreignId('version_id')->constrained('game_versions')->restrictOnDelete();
                $table->foreignId('period_id')->constrained('game_traffic_periods')->restrictOnDelete();
                $table->dateTime('assigned_at');
                $table->dateTime('last_seen_at');
                $table->dateTime('ended_at')->nullable();
                $table->timestamps();
            });

            DB::statement('ALTER TABLE game_assignments ADD CONSTRAINT game_assignments_identity_check CHECK ('.self::IDENTITY_CHECK.')');
        }

        Schema::table('game_assignments', function (Blueprint $table) {
            $table->unique(['game_id', 'audience', 'active_key'], 'game_assignments_active_unique');
            $table->index('user_id', 'game_assignments_user_index');
            $table->index('visitor_hash', 'game_assignments_visitor_index');
            $table->index(['version_id', 'assigned_at'], 'game_assignments_version_index');
            $table->index(['period_id', 'assigned_at'], 'game_assignments_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_assignments');
    }
};
