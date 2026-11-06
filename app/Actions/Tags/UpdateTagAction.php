<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class UpdateTagAction
{
    /**
     * A tag's English name and slug. Its translations are Translation
     * Center's and are never touched here.
     *
     * @param  array{name: string, slug?: ?string}  $data
     */
    public function handle(User $actor, Tag $tag, array $data): Tag
    {
        if (! $actor->can('update', $tag)) {
            throw new AuthorizationException('User is not allowed to update tags.');
        }

        $tag->update([
            'name' => $data['name'],
            'slug' => CreateTagAction::slug($data['name'], $data['slug'] ?? null),
        ]);

        return $tag;
    }
}
