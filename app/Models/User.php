<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Actions\Moderation\MarkUserTrustedAction;
use App\Enums\ProfileActivityVisibility;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'display_name', 'username', 'email', 'locale', 'theme_preference', 'notify_followed_author_posts', 'avatar_url', 'avatar_path', 'bio', 'profile_website_url', 'rating_activity_visibility', 'role', 'status', 'trust_level', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'trust_level' => 'integer',
            'notify_followed_author_posts' => 'boolean',
            'rating_activity_visibility' => ProfileActivityVisibility::class,
        ];
    }

    public function canCreateContent(): bool
    {
        return $this->status?->canCreateContent() ?? false;
    }

    public function canVote(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function canComment(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function canReport(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isModerator(): bool
    {
        return $this->role === UserRole::Moderator;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function postSaves(): HasMany
    {
        return $this->hasMany(PostSave::class);
    }

    public function savedPostItems(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_saves')->withTimestamps();
    }

    public function followingRelations(): HasMany
    {
        return $this->hasMany(Follow::class, 'follower_id');
    }

    public function followerRelations(): HasMany
    {
        return $this->hasMany(Follow::class, 'author_id');
    }

    public function followingAuthors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'author_id')->withTimestamps();
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'author_id', 'follower_id')->withTimestamps();
    }

    public function getResolvedDisplayNameAttribute(): string
    {
        return $this->display_name ?: ($this->name ?: $this->username);
    }

    public function getResolvedAvatarUrlAttribute(): ?string
    {
        if ($this->avatar_path) {
            return Storage::disk('public')->url($this->avatar_path);
        }

        return $this->avatar_url;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'admin') {
            return false;
        }

        return $this->status === UserStatus::Active
            && ($this->isAdmin() || $this->isModerator());
    }
}
