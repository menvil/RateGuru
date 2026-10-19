<?php

namespace App\Models;

use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use Database\Factories\PersonaProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What Content Studio keeps about a Persona for itself: its character, how it
 * speaks, what it likes to talk about and what it never writes about, how
 * often it uses emoji, and how readily it would reply on its own.
 *
 * Internal and nothing else: the account's public face — its name, username,
 * bio and avatar — stays in `users` like anyone's, and none of this is ever
 * serialized with it. Every field is hidden, so a profile loaded alongside a
 * User and turned into an array or JSON carries no instructions out.
 *
 * Having one does not make an account a Persona; the role does. It is written
 * only by the Content Studio actions (CreatePersonaAction,
 * UpdatePersonaAction) and removed by account anonymization.
 *
 * @property PersonaEmojiFrequency $emoji_frequency
 * @property PersonaEngagement $engagement
 * @property int $max_replies_per_day
 */
#[Fillable(['short_description', 'character', 'speech_style', 'topics', 'never_writes_about', 'emoji_frequency', 'engagement', 'max_replies_per_day'])]
#[Hidden(['short_description', 'character', 'speech_style', 'topics', 'never_writes_about', 'emoji_frequency', 'engagement', 'max_replies_per_day', 'created_by_user_id'])]
class PersonaProfile extends Model
{
    /** @use HasFactory<PersonaProfileFactory> */
    use HasFactory;

    /** The longest short description, in characters. */
    public const SHORT_DESCRIPTION_LIMIT = 500;

    /** The longest character, speech style, topics or never-writes-about text, in characters. */
    public const NOTES_LIMIT = 2000;

    /** The most replies a Persona may be allowed in a day. */
    public const MOST_REPLIES_PER_DAY = 50;

    /**
     * A new profile replies to nobody until an administrator says otherwise.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'emoji_frequency' => 'none',
        'engagement' => 'off',
        'max_replies_per_day' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'emoji_frequency' => PersonaEmojiFrequency::class,
            'engagement' => PersonaEngagement::class,
            'max_replies_per_day' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The administrator who created the Persona; null once that account no
     * longer exists at all.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
