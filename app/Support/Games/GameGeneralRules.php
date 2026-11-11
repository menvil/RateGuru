<?php

namespace App\Support\Games;

use App\Data\Games\GameGeneralData;
use App\Enums\GameMechanic;
use App\Models\Game;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The rules a game's public identity is held to, by the General tab and again
 * by the actions that save it: a title, a slug that is a clean, unused web
 * address, an optional description of a short line, and a mechanic the site
 * can play. The internal name falls back to the title.
 */
final class GameGeneralRules
{
    public const TITLE_MAX = 120;

    public const DESCRIPTION_MAX = 240;

    public const SLUG_MAX = 80;

    public const SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /**
     * The data as it will be stored, trimmed and checked, or a validation
     * error keyed as the editor's fields.
     *
     * @throws ValidationException
     */
    public function validated(GameGeneralData $data, ?Game $game = null): GameGeneralData
    {
        $values = [
            'title' => trim($data->title),
            'internalName' => trim($data->internalName) !== '' ? trim($data->internalName) : trim($data->title),
            'slug' => Str::lower(trim($data->slug)),
            'description' => trim($data->description),
            'mechanic' => $data->mechanic,
        ];

        Validator::make($values, [
            'title' => ['required', 'string', 'max:'.self::TITLE_MAX],
            'internalName' => ['required', 'string', 'max:'.self::TITLE_MAX],
            'slug' => ['required', 'string', 'max:'.self::SLUG_MAX, 'regex:'.self::SLUG_PATTERN, Rule::unique('games', 'slug')->ignore($game?->id)],
            'description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            'mechanic' => ['required', Rule::enum(GameMechanic::class)],
        ], [
            'title.required' => 'Add a public title.',
            'slug.required' => 'Add a slug.',
            'slug.regex' => 'Use lowercase letters, numbers and single hyphens only.',
            'slug.unique' => 'Another game already uses this slug.',
            'mechanic.required' => 'Choose a mechanic to continue.',
        ])->validate();

        return new GameGeneralData(...$values);
    }
}
