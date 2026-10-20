<?php

namespace App\Queries\Personas;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\PersonaEngagement;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Database\LikePattern;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Content Studio's Personas, as its list reads them: every account with the
 * Persona role that is not deleted, newest first, narrowed by its status and
 * its engagement and searched over its username, its names, its short
 * description and its character. A Persona without a profile yet reads as one
 * whose engagement is Off, which is what its first save would give it.
 */
final class PersonasQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    public const PER_PAGE = 25;

    /** The statuses a Persona can be listed by: every living one. */
    public const STATUSES = [UserStatus::Active, UserStatus::Limited, UserStatus::Banned, UserStatus::Shadowbanned];

    /**
     * A page of Personas, each with its profile and its avatar.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(string $search, ?UserStatus $status, ?PersonaEngagement $engagement, int $page): LengthAwarePaginator
    {
        return $this->narrowed($search, $status, $engagement)
            ->with(['personaProfile', 'avatarAsset.variants'])
            // Personas made at the same moment keep their place across pages.
            ->orderByDesc('users.created_at')
            ->orderByDesc('users.id')
            ->paginate(self::PER_PAGE, ['users.*'], 'page', max(1, $page));
    }

    /**
     * The Personas a publication plan's author is chosen from: every living
     * one matching the search as the list's does, newest first, at most
     * $limit of them — never every Persona at once.
     *
     * @return Collection<int, User>
     */
    public function choices(string $search, int $limit): Collection
    {
        return $this->narrowed($search, null, null)
            ->with('avatarAsset.variants')
            ->orderByDesc('users.created_at')
            ->orderByDesc('users.id')
            ->limit(max(1, $limit))
            ->get(['users.*']);
    }

    /**
     * As many living Personas as asked for, at most, picked at random — the
     * team a discussion is to be written by when an administrator asks for a
     * random one, shown to them before anything is generated. Never every
     * Persona at once.
     *
     * @return Collection<int, User>
     */
    public function randomChoosable(int $count): Collection
    {
        return $this->personas()
            ->with('avatarAsset.variants')
            ->inRandomOrder()
            ->limit(max(1, $count))
            ->get(['users.*']);
    }

    /** The living Persona with this id, if there is one: what a publication plan's author may be. */
    public function choosable(int $id): ?User
    {
        return $this->personas()->whereKey($id)->first();
    }

    /**
     * The figures the list's header shows: every Persona, the active ones,
     * the ones under a sanction, and the ones that would reply on their own.
     *
     * @return array{total: int, active: int, restricted: int, replying: int}
     */
    public function stats(): array
    {
        return [
            'total' => $this->personas()->count(),
            'active' => $this->personas()->where('users.status', UserStatus::Active)->count(),
            'restricted' => $this->personas()->whereIn('users.status', [UserStatus::Limited, UserStatus::Banned, UserStatus::Shadowbanned])->count(),
            'replying' => $this->personas()
                ->whereHas('personaProfile', fn (Builder $profile) => $profile->where('engagement', '!=', PersonaEngagement::Off))
                ->count(),
        ];
    }

    /** @return Builder<User> */
    private function personas(): Builder
    {
        return User::query()
            ->where('users.role', UserRole::Persona)
            ->withoutTombstoned();
    }

    /** @return Builder<User> */
    private function narrowed(string $search, ?UserStatus $status, ?PersonaEngagement $engagement): Builder
    {
        $query = $this->personas();
        $search = trim($search);

        if ($status !== null) {
            $query->where('users.status', $status);
        }

        if ($engagement === PersonaEngagement::Off) {
            $query->where(fn (Builder $off) => $off
                ->whereDoesntHave('personaProfile')
                ->orWhereHas('personaProfile', fn (Builder $profile) => $profile->where('engagement', PersonaEngagement::Off)));
        } elseif ($engagement !== null) {
            $query->whereHas('personaProfile', fn (Builder $profile) => $profile->where('engagement', $engagement));
        }

        if ($search !== '') {
            $pattern = LikePattern::containing(ltrim($search, '@'));

            $query->where(fn (Builder $matching) => $matching
                ->whereRaw("LOWER(users.username) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(users.name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(users.display_name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereHas('personaProfile', fn (Builder $profile) => $profile
                    ->whereRaw("LOWER(persona_profiles.short_description) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(persona_profiles.character) LIKE LOWER(?) ESCAPE '!'", [$pattern])));
        }

        return $query;
    }
}
