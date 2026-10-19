<?php

namespace App\Support\Personas;

use App\Data\Personas\PersonaData;
use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use App\Models\PersonaProfile;
use App\Models\User;
use App\Support\Profile\ProfileValidationRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The one set of rules a Persona is held to, keyed as the editor's fields:
 * the editor checks a draft with them to put each message by its field, and
 * the Content Studio actions check whatever reaches them with them again, so
 * no request is trusted to have been checked.
 *
 * The public fields follow a member's own (ProfileValidationRules) and the
 * username the rules every account keeps; a display name is required, since
 * it is all a Persona is known by on the site.
 */
final class PersonaValidationRules
{
    /** What a username is made of: lowercase Latin letters, digits and underscores. */
    public const USERNAME_PATTERN = '/\A[a-z0-9_]+\z/';

    /** The longest username, in characters. */
    public const USERNAME_LIMIT = 32;

    /**
     * @param  User|null  $persona  the Persona being edited, or null for a new one — which may leave its username to be made from its display name
     * @param  bool  $keepsUsername  whether the username is the one the Persona has: kept as it is, it is not held to today's rules
     * @return array<string, list<mixed>>
     */
    public function rules(?User $persona, bool $keepsUsername = false): array
    {
        $unique = Rule::unique(User::class, 'username');

        return [
            'displayName' => ['required', 'string', 'max:'.ProfileValidationRules::DISPLAY_NAME_LIMIT],
            'username' => match (true) {
                $persona !== null && $keepsUsername => ['required', 'string'],
                default => [
                    $persona === null ? 'nullable' : 'required',
                    'string',
                    'regex:'.self::USERNAME_PATTERN,
                    'max:'.self::USERNAME_LIMIT,
                    $persona === null ? $unique : $unique->ignore($persona->getKey()),
                ],
            },
            'bio' => ['nullable', 'string', 'max:'.ProfileValidationRules::BIO_LIMIT],
            'shortDescription' => ['nullable', 'string', 'max:'.PersonaProfile::SHORT_DESCRIPTION_LIMIT],
            'character' => ['nullable', 'string', 'max:'.PersonaProfile::NOTES_LIMIT],
            'speechStyle' => ['nullable', 'string', 'max:'.PersonaProfile::NOTES_LIMIT],
            'topics' => ['nullable', 'string', 'max:'.PersonaProfile::NOTES_LIMIT],
            'neverWritesAbout' => ['nullable', 'string', 'max:'.PersonaProfile::NOTES_LIMIT],
            'emojiFrequency' => ['required', Rule::enum(PersonaEmojiFrequency::class)],
            'engagement' => ['required', Rule::enum(PersonaEngagement::class)],
            'maxRepliesPerDay' => ['required', 'integer', 'between:0,'.PersonaProfile::MOST_REPLIES_PER_DAY],
        ];
    }

    /**
     * What each message says to do.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $notes = 'Keep it to '.number_format(PersonaProfile::NOTES_LIMIT).' characters or fewer.';

        return [
            'displayName.required' => 'Enter a display name.',
            'displayName.max' => 'Keep the display name to '.ProfileValidationRules::DISPLAY_NAME_LIMIT.' characters or fewer.',
            'username.required' => 'Enter a username.',
            'username.regex' => 'Use lowercase letters, numbers and underscores.',
            'username.max' => 'Keep the username to '.self::USERNAME_LIMIT.' characters or fewer.',
            'username.unique' => 'Another account already uses this username. Choose another one.',
            'bio.max' => 'Keep the bio to '.ProfileValidationRules::BIO_LIMIT.' characters or fewer.',
            'shortDescription.max' => 'Keep the short description to '.PersonaProfile::SHORT_DESCRIPTION_LIMIT.' characters or fewer.',
            'character.max' => $notes,
            'speechStyle.max' => $notes,
            'topics.max' => $notes,
            'neverWritesAbout.max' => $notes,
            'emojiFrequency.*' => 'Choose how often it uses emoji.',
            'engagement.*' => 'Choose an engagement level.',
            'maxRepliesPerDay.*' => 'Enter a whole number from 0 to '.PersonaProfile::MOST_REPLIES_PER_DAY.'.',
        ];
    }

    /**
     * The draft as it will be stored, once it passes every rule.
     *
     * @throws ValidationException keyed as the editor's fields
     */
    public function validated(PersonaData $data, ?User $persona): PersonaData
    {
        $data = $data->normalized();

        $keepsUsername = $persona !== null && $data->username === $persona->username;

        Validator::make($data->toArray(), $this->rules($persona, $keepsUsername), $this->messages())->validate();

        return $data;
    }
}
