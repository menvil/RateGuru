<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which English each stored project translation was made from: one row per
 * unit and language, written with the translation by
 * UpdateProjectTranslationAction, so Translation Center can tell a translation
 * whose English has changed since. A translation with no row is not known to
 * be outdated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_translation_sources', function (Blueprint $table) {
            $table->id();

            // The catalog's unit id, `categories:17:name`, and the installed language translated into.
            $table->string('unit_id', 120);
            $table->string('locale', 35);

            // ProjectTranslationSourceFingerprint of the unit id and its English, as the translation was saved.
            $table->char('fingerprint', 64);
            // That English text itself, to show what the translation was made from. A static page runs to
            // 20,000 characters, more than MariaDB's TEXT holds in four-byte characters.
            $table->mediumText('reference');

            $table->timestamps();

            $table->unique(['unit_id', 'locale'], 'project_translation_sources_unit_locale_unique');
            $table->index('locale', 'project_translation_sources_locale_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_translation_sources');
    }
};
