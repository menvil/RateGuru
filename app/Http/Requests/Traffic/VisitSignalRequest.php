<?php

namespace App\Http\Requests\Traffic;

use App\Queries\Posts\PublishedPostDetailsQuery;
use App\Support\Traffic\Capture\ArrivalToken;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a page's script tells the traffic tracker. Anyone may send it; a post
 * it says was shared has to be one its own page would show. A sign-in or
 * sign-up form may name the post whose page it opened on, which is only ever
 * matched against the browser's own plays, so it needs no such check.
 */
final class VisitSignalRequest extends FormRequest
{
    /** @var list<string> the fields of a `presence` signal, as traffic.js names them */
    public const array PRESENCE_FIELDS = [
        'vis0', 'tvis', 'visms', 'tin', 'inkind', 'ins', 'unt', 'hin', 'wd', 'auto', 'botd', 'frm', 'gl',
        'tz', 'tzo', 'langs', 'plat', 'sw', 'sh', 'ww', 'wh', 'ow', 'oh', 'dpr', 'mtp', 'hc', 'dm', 'plg',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'signal' => ['required', 'string', 'in:opened,signup_form,post_shared,presence'],
            'arrival' => ['nullable', 'string', 'max:'.ArrivalToken::MAX_LENGTH],
            'post' => [
                // Read only with the signals that name a post: a share always does, a form on a post's page.
                'exclude_unless:signal,post_shared,signup_form',
                'bail',
                'required_if:signal,post_shared',
                'nullable',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->input('signal') === 'post_shared' && ! app(PublishedPostDetailsQuery::class)->isPublished((int) $value)) {
                        $fail('The post is not published.');
                    }
                },
            ],
            // A sign-up form's signal from the sign-in dialog: the state it shows, and what opened it (AuthTrigger, one the
            // server doesn't know being anything else). Without an opener — a page's script older than the dialog's steps —
            // no step of the dialog is recorded.
            'mode' => ['exclude_unless:signal,signup_form', 'nullable', 'string', 'in:login,register'],
            'trigger' => ['exclude_unless:signal,signup_form', 'nullable', 'string', 'max:32'],
            // What a landing page measured of its visitor (PresenceFacts, which caps and words each one again): read only
            // with that signal, each a short scalar, so a report is a few hundred bytes whatever a script sends.
            ...array_fill_keys(self::PRESENCE_FIELDS, ['exclude_unless:signal,presence', 'nullable', 'string', 'max:64']),
        ];
    }
}
