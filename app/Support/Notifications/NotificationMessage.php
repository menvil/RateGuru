<?php

namespace App\Support\Notifications;

use App\Support\Locale\IsolatedTranslation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\HtmlString;

/**
 * The text of one in-app notification, decided at READ time.
 *
 * A notification is written once and read for as long as it sits in the bell —
 * possibly in a language the reader had not chosen yet when it was created, and
 * possibly by someone whose language changed in between. So the sentence cannot
 * be stored; only the key and its parameters can.
 *
 * Three payload shapes exist and all three have to render:
 *
 *   1. `message_key` + `message_params` — everything written from now on;
 *   2. `message` — rows written before that, kept readable rather than
 *      rewritten, because a backfill can be wrong and a fallback cannot;
 *   3. neither — a payload from a notification type nobody anticipated.
 *
 * Shape 2 renders in the language it was stored in, which is English. That is
 * not a regression: it is exactly what those rows have always shown, and the
 * alternative — guessing a key by matching English prose — is the kind of
 * cleverness that quietly mistranslates.
 */
final class NotificationMessage
{
    /**
     * The parameters that carry text people wrote — a post's title — read
     * their own way inside the sentence in the bell (see html()). The @name
     * is not among them: the translations put a left-to-right mark before
     * "@:username" so the "@" stays in front of the name, and an isolate
     * around the name alone would cut the "@" off it.
     */
    private const WRITTEN_PARAMETERS = ['title'];

    public static function for(DatabaseNotification $notification): string
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        $key = $data['message_key'] ?? null;

        if (is_string($key) && $key !== '') {
            $params = $data['message_params'] ?? [];

            return __($key, is_array($params) ? $params : []);
        }

        $legacy = $data['message'] ?? null;

        if (is_string($legacy) && $legacy !== '') {
            return $legacy;
        }

        return __('ui.notifications.fallback_message');
    }

    /**
     * The same text as for(), as the bell draws it: a post's title put into
     * the sentence sits in a <bdi>, so an English title keeps its punctuation
     * at its end in an Arabic sentence and an Arabic title in an English one.
     */
    public static function html(DatabaseNotification $notification): HtmlString
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        $key = $data['message_key'] ?? null;

        if (! is_string($key) || $key === '') {
            return new HtmlString(e(self::for($notification)));
        }

        $params = is_array($data['message_params'] ?? null) ? $data['message_params'] : [];
        $written = array_intersect_key($params, array_flip(self::WRITTEN_PARAMETERS));

        return IsolatedTranslation::line($key, array_map(strval(...), $written), array_diff_key($params, $written));
    }
}
