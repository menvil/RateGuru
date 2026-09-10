<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give notifications written before the switch a translatable message.
 *
 * They stored a rendered English sentence. This does NOT parse that sentence:
 * every payload already carries the structured fields the sentence was built
 * from — `type`, `actor_username`, `author_username`, `post_title` — so the key
 * and its parameters are reconstructed from data, not from prose. Matching
 * English text would break on any row written in a different phrasing and
 * would be undetectably wrong when it half-matched.
 *
 * `message` is deliberately left in place. NotificationMessage prefers the key
 * when it is there, so the old value is inert — and if a row here is somehow
 * wrong, the reader still has the sentence it always had rather than a blank.
 *
 * Only the three types that existed are touched. Anything else keeps its own
 * payload and renders through the legacy branch.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> type => the payload fields its message needs */
    private const TYPES = [
        'post_approved' => [],
        'post_commented' => ['actor_username'],
        'followed_author_posted' => ['author_username', 'post_title'],
    ];

    public function up(): void
    {
        DB::table('notifications')
            ->orderBy('id')
            ->chunkById(500, function (iterable $rows): void {
                foreach ($rows as $row) {
                    $update = $this->rewrite($row->data);

                    if ($update === null) {
                        continue;
                    }

                    DB::table('notifications')->where('id', $row->id)->update(['data' => $update]);
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: `message` was never removed, so the previous
        // renderer reads exactly what it always read.
    }

    private function rewrite(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return null;
        }

        // Idempotent: a row that already carries a key is finished.
        if (isset($data['message_key'])) {
            return null;
        }

        $type = $data['type'] ?? null;

        if (! is_string($type) || ! array_key_exists($type, self::TYPES)) {
            return null;
        }

        $params = [];

        foreach (self::TYPES[$type] as $field) {
            $value = $data[$field] ?? null;

            // A payload missing the field its sentence was built from cannot be
            // rebuilt honestly; leave it to render as it always has.
            if (! is_string($value) || $value === '') {
                return null;
            }

            $params[$field === 'post_title' ? 'title' : 'username'] = $value;
        }

        $data['message_key'] = 'ui.notifications.messages.'.$type;
        $data['message_params'] = $params;

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
};
