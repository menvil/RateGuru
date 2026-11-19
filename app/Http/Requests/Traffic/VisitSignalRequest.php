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
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'signal' => ['required', 'string', 'in:opened,signup_form,post_shared'],
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
        ];
    }
}
