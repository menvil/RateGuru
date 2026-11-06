<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

final class CreateTagAction
{
    /**
     * A tag in English, with nothing translated yet. Its slug is the one
     * given, or one made from its name when none is.
     *
     * @param  array{name: string, slug?: ?string}  $data
     */
    public function handle(User $actor, array $data): Tag
    {
        if (! $actor->can('create', Tag::class)) {
            throw new AuthorizationException('User is not allowed to create tags.');
        }

        return Tag::query()->create([
            'name' => $data['name'],
            'slug' => self::slug($data['name'], $data['slug'] ?? null),
        ]);
    }

    /**
     * The slug a tag is saved with: the one given, made URL-safe, or, when
     * none is, one made from its name. A blank check rather than a truthy
     * one, so a deliberate slug like "0" is kept.
     */
    public static function slug(string $name, ?string $slug): string
    {
        return Str::slug(filled($slug) ? $slug : $name);
    }
}
