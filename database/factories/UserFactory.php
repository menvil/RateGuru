<?php

namespace Database\Factories;

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'avatar_asset_id' => null,
            'role' => UserRole::User,
            'status' => UserStatus::Active,
            'trust_level' => MarkUserTrustedAction::TRUSTED_LEVEL,
            'notify_followed_author_posts' => true,
            'rating_activity_visibility' => 'private',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Admin,
        ]);
    }

    public function moderator(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Moderator,
        ]);
    }

    public function banned(): static
    {
        return $this->state(fn () => [
            'status' => UserStatus::Banned,
        ]);
    }

    public function limited(): static
    {
        return $this->state(fn () => [
            'status' => UserStatus::Limited,
        ]);
    }

    public function shadowbanned(): static
    {
        return $this->state(fn () => [
            'status' => UserStatus::Shadowbanned,
        ]);
    }

    /**
     * A Persona: a managed account with no password, no remember token, no
     * sign-in identity and an unconfirmed synthetic address on the reserved
     * .invalid domain. Trust is the default, as for any new account; its
     * posts wait for moderation whatever its trust (CreatePostAction).
     */
    public function persona(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Persona,
            'status' => UserStatus::Active,
            'email' => 'persona-'.Str::uuid()->toString().'@persona.invalid',
            'email_verified_at' => null,
            'password' => null,
            'remember_token' => null,
        ]);
    }

    /**
     * A minimal already-tombstoned account for capability/guard tests.
     * Production tombstones are only ever produced by
     * AnonymizeUserAccountAction — use that in tests asserting the full
     * anonymized field set.
     */
    public function tombstoned(): static
    {
        return $this->state(fn () => [
            'status' => UserStatus::Deleted,
            'anonymized_at' => now(),
        ]);
    }

    public function trusted(): static
    {
        return $this->state(fn () => [
            'trust_level' => MarkUserTrustedAction::TRUSTED_LEVEL,
            'status' => UserStatus::Active,
        ]);
    }

    /**
     * A social-only account: no password at all, exactly as
     * RegisterSocialUserAction creates one — never a placeholder hash.
     */
    public function withoutPassword(): static
    {
        return $this->state(fn () => [
            'password' => null,
        ]);
    }

    /**
     * Attaches a real MediaAsset (kind: avatar) as this user's avatar.
     * Pass path/disk to control the exact identity assertions in a test rely
     * on. The asset is only created in afterCreating() — a state closure runs
     * for make() too, and make() must not touch the database.
     */
    public function withAvatar(?string $path = null, ?string $disk = null): static
    {
        return $this->afterCreating(function (User $user) use ($path, $disk): void {
            $asset = MediaAsset::factory()->avatar()->create([
                'disk' => $disk ?? 'public',
                'path' => $path ?? 'avatars/'.Str::uuid()->toString().'.jpg',
            ]);

            $user->update(['avatar_asset_id' => $asset->id]);
        });
    }
}
