<?php

namespace App\Support\Games;

use App\Data\Games\ContentSetDraft;
use App\Enums\GameOptionSource;
use App\Enums\GameProfileIcon;
use App\Enums\GameRoundMode;
use App\Models\GameContentSet;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What a saved content set may hold, whatever an editor sends: well-formed
 * rounds of exactly two options, texts within their lengths, scoring within
 * its bounds, profiles with whole ranges and known badges. A draft may still
 * be incomplete — a question not written yet, an option without its picture —
 * which is the publish report's to say, not a reason to refuse the save.
 */
final class ContentSetDraftRules
{
    public const QUESTION_MAX = 120;

    public const DESCRIPTION_MAX = 240;

    public const REVEAL_MAX = 200;

    public const LABEL_MAX = 60;

    public const INTERNAL_NAME_MAX = 80;

    public const PROFILE_NAME_MAX = 60;

    public const PROFILE_DESCRIPTION_MAX = 240;

    public const SHARE_TEXT_MAX = 200;

    public const MOST_ROUNDS = 50;

    public const MOST_PROFILES = 12;

    /** @throws ValidationException keyed as the draft's own paths */
    public function validated(ContentSetDraft $draft, GameContentSet $set): ContentSetDraft
    {
        $data = $draft->toArray();

        Validator::make($data, [
            'scoring.points_per_round' => ['required', 'integer', 'between:1,10'],
            'scoring.crowd_min_votes' => ['required', 'integer', 'between:1,1000000'],
            'scoring.crowd_min_gap' => ['required', 'integer', 'between:0,50'],
            'rounds' => ['array', 'max:'.self::MOST_ROUNDS],
            'rounds.*.id' => ['nullable', 'integer', Rule::exists('game_rounds', 'id')->where('content_set_id', $set->id)],
            'rounds.*.key' => ['required', 'string', 'max:40', 'distinct'],
            'rounds.*.internal_name' => ['required', 'string', 'max:'.self::INTERNAL_NAME_MAX],
            'rounds.*.question' => ['nullable', 'string', 'max:'.self::QUESTION_MAX],
            'rounds.*.description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            'rounds.*.reveal_message' => ['nullable', 'string', 'max:'.self::REVEAL_MAX],
            'rounds.*.mode' => ['required', Rule::enum(GameRoundMode::class)],
            'rounds.*.unscored' => ['required', 'boolean'],
            'rounds.*.show_crowd' => ['required', 'boolean'],
            'rounds.*.options' => ['required', 'array', 'size:2'],
            'rounds.*.options.*.source' => ['required', Rule::enum(GameOptionSource::class)],
            'rounds.*.options.*.post_id' => ['nullable', 'integer', Rule::exists('posts', 'id')],
            'rounds.*.options.*.asset_id' => ['nullable', 'integer'],
            'rounds.*.options.*.label' => ['nullable', 'string', 'max:'.self::LABEL_MAX],
            'rounds.*.options.*.is_correct' => ['required', 'boolean'],
            'profiles' => ['array', 'max:'.self::MOST_PROFILES],
            'profiles.*.id' => ['nullable', 'integer', Rule::exists('game_result_profiles', 'id')->where('content_set_id', $set->id)],
            'profiles.*.key' => ['nullable', 'string', 'max:40'],
            'profiles.*.name' => ['required', 'string', 'max:'.self::PROFILE_NAME_MAX],
            'profiles.*.description' => ['nullable', 'string', 'max:'.self::PROFILE_DESCRIPTION_MAX],
            'profiles.*.min' => ['required', 'integer', 'between:0,'.self::MOST_ROUNDS],
            'profiles.*.max' => ['required', 'integer', 'between:0,'.self::MOST_ROUNDS],
            'profiles.*.icon' => ['required', Rule::enum(GameProfileIcon::class)],
            'profiles.*.share_text' => ['nullable', 'string', 'max:'.self::SHARE_TEXT_MAX],
            'profiles.*.share_asset_id' => ['nullable', 'integer'],
        ], [
            'rounds.*.internal_name.required' => 'Give the round a name.',
            'rounds.*.question.max' => 'Keep the question to '.self::QUESTION_MAX.' characters.',
            'profiles.*.name.required' => 'Give the profile a name.',
        ])->validate();

        return new ContentSetDraft(
            scoring: [
                'points_per_round' => (int) $data['scoring']['points_per_round'],
                'crowd_min_votes' => (int) $data['scoring']['crowd_min_votes'],
                'crowd_min_gap' => (int) $data['scoring']['crowd_min_gap'],
            ],
            rounds: array_values(array_map(fn (array $round): array => self::cleanRound($round), $data['rounds'])),
            profiles: self::withKeys(array_values($data['profiles'])),
        );
    }

    /** @param  array<string, mixed>  $round */
    private static function cleanRound(array $round): array
    {
        $round['internal_name'] = trim((string) $round['internal_name']);

        foreach (['question', 'description', 'reveal_message'] as $field) {
            $round[$field] = trim((string) ($round[$field] ?? ''));
        }

        $round['options'] = array_values(array_map(function (array $option): array {
            $upload = $option['source'] === GameOptionSource::Upload->value;

            return [
                'source' => $option['source'],
                // An option shows a post or an upload, never both.
                'post_id' => $upload ? null : (isset($option['post_id']) ? (int) $option['post_id'] : null),
                'asset_id' => $upload && isset($option['asset_id']) ? (int) $option['asset_id'] : null,
                'label' => trim((string) ($option['label'] ?? '')),
                'is_correct' => (bool) $option['is_correct'],
            ];
        }, $round['options']));

        return $round;
    }

    /**
     * Every profile with a key unique within the set: its own, or one made
     * from its name.
     *
     * @param  list<array<string, mixed>>  $profiles
     * @return list<array<string, mixed>>
     */
    private static function withKeys(array $profiles): array
    {
        $used = [];

        foreach ($profiles as $index => $profile) {
            // A saved profile keeps its key, which shared results name; a new
            // one takes its name's.
            $base = ($profile['id'] ?? null) !== null ? Str::slug((string) ($profile['key'] ?? '')) : '';
            $base = $base !== '' ? $base : (Str::slug((string) $profile['name']) ?: 'profile');
            $base = Str::limit($base, 34, '');
            $key = $base;

            for ($suffix = 2; in_array($key, $used, true); $suffix++) {
                $key = $base.'-'.$suffix;
            }

            $used[] = $key;
            $profiles[$index] = [
                ...$profile,
                // What the editor named it by, so a picture chosen for it finds it.
                'client_key' => (string) ($profile['key'] ?? ''),
                'key' => $key,
                'name' => trim((string) $profile['name']),
                'description' => trim((string) ($profile['description'] ?? '')),
                'share_text' => trim((string) ($profile['share_text'] ?? '')),
                'min' => (int) $profile['min'],
                'max' => (int) $profile['max'],
            ];
        }

        return $profiles;
    }
}
