<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the English entry from every `{field}_translations` column.
 *
 * A translatable field keeps its English in the base column (`name`) and the
 * other languages beside it (`name_translations`). Presets and the default
 * seeder also wrote English among the translations, and the reader preferred
 * that copy for an English visitor — so once English was edited only in the
 * base column, the site went on showing the old copy. The reader now always
 * takes English from the base column; this removes the stale copies, so no
 * part of the application can read one again.
 *
 * The base column is what every editor and Translation Center show and
 * translate from, so it is kept as it is. Only where it is blank and the copy
 * has text — which an English visitor saw until now — does the copy move into
 * it. A map left empty is stored as null, the way the editors leave it.
 *
 * Idempotent, and nothing to undo: the copies duplicated the base column.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => the base columns whose translations sit beside them */
    private const FIELDS = [
        'categories' => ['name'],
        'tags' => ['name'],
        'rating_groups' => ['label', 'description'],
        'rating_options' => ['label', 'description'],
        'project_settings' => ['site_name', 'site_tagline', 'site_description', 'object_singular_name', 'object_plural_name', 'upload_cta_label', 'feed_title'],
    ];

    public function up(): void
    {
        foreach (self::FIELDS as $table => $fields) {
            $columns = ['id', ...$fields, ...array_map(fn (string $field): string => "{$field}_translations", $fields)];

            DB::table($table)->select($columns)->orderBy('id')->chunkById(500, function (iterable $rows) use ($table, $fields): void {
                foreach ($rows as $row) {
                    $update = [];

                    foreach ($fields as $field) {
                        $update += $this->withoutEnglish($field, $row->{$field}, $row->{"{$field}_translations"});
                    }

                    if ($update !== []) {
                        DB::table($table)->where('id', $row->id)->update($update);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Nothing to restore: the English entries copied the base column.
    }

    /**
     * The columns to write for one field, or none when it holds no English entry.
     *
     * @return array<string, ?string>
     */
    private function withoutEnglish(string $field, mixed $base, mixed $raw): array
    {
        $translations = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($translations) || ! array_key_exists('en', $translations)) {
            return [];
        }

        $english = $translations['en'];
        unset($translations['en']);
        $update = ["{$field}_translations" => $translations === [] ? null : json_encode($translations, JSON_UNESCAPED_UNICODE)];

        if ((! is_string($base) || trim($base) === '') && is_string($english) && trim($english) !== '') {
            $update[$field] = $english;
        }

        return $update;
    }
};
