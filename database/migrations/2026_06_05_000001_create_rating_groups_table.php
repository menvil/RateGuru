<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteTable();

            return;
        }

        Schema::create('rating_groups', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('min_options')->default(2);
            $table->unsignedTinyInteger('max_options')->default(10);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE rating_groups ADD CONSTRAINT rating_groups_option_range_check '
            .'CHECK (min_options <= max_options)'
        );
        DB::statement(
            'ALTER TABLE rating_groups ADD CONSTRAINT rating_groups_min_options_bounds_check '
            .'CHECK (min_options >= 0 AND min_options <= 255)'
        );
        DB::statement(
            'ALTER TABLE rating_groups ADD CONSTRAINT rating_groups_max_options_bounds_check '
            .'CHECK (max_options >= 0 AND max_options <= 255)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_groups');
    }

    private function createSqliteTable(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE rating_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                "key" VARCHAR NOT NULL,
                label VARCHAR NOT NULL,
                description TEXT,
                min_options INTEGER NOT NULL DEFAULT 2,
                max_options INTEGER NOT NULL DEFAULT 10,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME,
                updated_at DATETIME,
                CONSTRAINT rating_groups_key_unique UNIQUE ("key"),
                CONSTRAINT rating_groups_min_options_bounds_check CHECK (min_options >= 0 AND min_options <= 255),
                CONSTRAINT rating_groups_max_options_bounds_check CHECK (max_options >= 0 AND max_options <= 255),
                CONSTRAINT rating_groups_option_range_check CHECK (min_options <= max_options)
            )
        SQL);
    }
};
