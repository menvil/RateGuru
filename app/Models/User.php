<?php

namespace App\Models;

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Enums\ProfileActivityVisibility;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Support\Media\AvatarUrlResolver;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property UserRole|null $role
 * @property UserStatus|null $status
 * @property int|null $trust_level
 * @property ProfileActivityVisibility|null $rating_activity_visibility
 * @property Carbon|null $anonymized_at
 */
#[Fillable(['name', 'display_name', 'username', 'email', 'locale', 'theme_preference', 'notify_followed_author_posts', 'avatar_asset_id', 'bio', 'profile_website_url', 'rating_activity_visibility', 'role', 'status', 'trust_level', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The only author label public UI may show for a tombstoned account —
     * also stored into `name` by AnonymizeUserAccountAction so raw
     * `$user->name` render sites stay safe.
     */
    public const TOMBSTONE_DISPLAY_NAME = 'Deleted user';

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if ($user->trust_level === null) {
                $user->trust_level = MarkUserTrustedAction::TRUSTED_LEVEL;
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'trust_level' => 'integer',
            'notify_followed_author_posts' => 'boolean',
            'rating_activity_visibility' => ProfileActivityVisibility::class,
        ];
    }

    // Lifecycle capability conveniences. Every method delegates to the
    // central contract on UserStatus and fails closed when status is null —
    // no lifecycle meaning may live here (docs/architecture/user-lifecycle.md).

    public function canCreateContent(): bool
    {
        return $this->status?->canCreateContent() ?? false;
    }

    public function canVote(): bool
    {
        return $this->status?->canVote() ?? false;
    }

    public function canComment(): bool
    {
        return $this->status?->canComment() ?? false;
    }

    public function canReport(): bool
    {
        return $this->status?->canReport() ?? false;
    }

    public function canFollow(): bool
    {
        return $this->status?->canFollow() ?? false;
    }

    public function canBeFollowed(): bool
    {
        return $this->status?->canBeFollowed() ?? false;
    }

    public function canManageContent(): bool
    {
        return $this->status?->canManageContent() ?? false;
    }

    public function canUpdateProfile(): bool
    {
        return $this->status?->canUpdateProfile() ?? false;
    }

    public function canAuthenticate(): bool
    {
        return $this->status?->canAuthenticate() ?? false;
    }

    public function canAccessPrivilegedPanel(): bool
    {
        return $this->status?->canAccessPrivilegedPanel() ?? false;
    }

    /**
     * Irreversible deleted-account tombstone (see
     * docs/architecture/user-lifecycle.md). Not a capability — a state
     * check used by the presentation boundary (accessors below), query
     * scopes and moderation guards.
     */
    public function isTombstoned(): bool
    {
        return $this->status === UserStatus::Deleted;
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeWithoutTombstoned(Builder $query): Builder
    {
        return $query->where('status', '!=', UserStatus::Deleted);
    }

    /**
     * A tombstone never receives notifications again: its inbox was purged
     * at anonymization and every channel address is scrambled/invalid.
     * Overriding the Notifiable entry point keeps this rule in one place
     * instead of at every ->notify() call site.
     */
    public function notify($instance): void
    {
        if ($this->isTombstoned()) {
            return;
        }

        app(NotificationDispatcher::class)->send($this, $instance);
    }

    public function isModerator(): bool
    {
        return $this->role === UserRole::Moderator;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<PostSave, $this> */
    public function postSaves(): HasMany
    {
        return $this->hasMany(PostSave::class);
    }

    /** @return BelongsToMany<Post, $this> */
    public function savedPostItems(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_saves')->withTimestamps();
    }

    /** @return HasMany<Follow, $this> */
    public function followingRelations(): HasMany
    {
        return $this->hasMany(Follow::class, 'follower_id');
    }

    /** @return HasMany<Follow, $this> */
    public function followerRelations(): HasMany
    {
        return $this->hasMany(Follow::class, 'author_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function followingAuthors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'author_id')->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'author_id', 'follower_id')->withTimestamps();
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function avatarAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'avatar_asset_id');
    }

    public function getResolvedDisplayNameAttribute(): string
    {
        if ($this->isTombstoned()) {
            return self::TOMBSTONE_DISPLAY_NAME;
        }

        return $this->display_name ?: ($this->name ?: $this->username);
    }

    /**
     * The username as it may be shown/linked in public UI. Tombstoned
     * accounts have no public handle: views that guard on this accessor
     * render plain "Deleted user" text with no @handle and no profile
     * link, exactly like a user without a username.
     */
    public function getPublicUsernameAttribute(): ?string
    {
        return $this->isTombstoned() ? null : $this->username;
    }

    /**
     * Temporary compatibility accessor kept only because Blade views and API
     * resources already call it in many places — removing it would ripple
     * across the presentation layer for no behavioral gain. It carries no
     * filesystem knowledge of its own: it just delegates to
     * AvatarUrlResolver, which delegates to MediaUrlResolver. New code
     * should prefer injecting AvatarUrlResolver directly.
     */
    public function getResolvedAvatarUrlAttribute(): ?string
    {
        return app(AvatarUrlResolver::class)->url($this);
    }

    /**
     * Same compatibility-accessor shape as getResolvedAvatarUrlAttribute()
     * above, for the srcset half of AvatarUrlResolver::responsive(). Reads
     * the already-loaded avatarAsset.variants relation — no query here
     * beyond what resolved_avatar_url already risks lazy-loading.
     */
    public function getResolvedAvatarSrcsetAttribute(): ?string
    {
        return app(AvatarUrlResolver::class)->responsive($this)?->srcset;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'admin') {
            return false;
        }

        // Two independent dimensions: lifecycle eligibility (status) AND
        // role. A banned/limited/shadowbanned admin fails closed; an active
        // regular user is lifecycle-eligible but lacks the role.
        return ($this->status?->canAccessPrivilegedPanel() ?? false)
            && ($this->isAdmin() || $this->isModerator());
    }
}
