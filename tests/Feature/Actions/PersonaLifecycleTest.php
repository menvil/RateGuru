<?php

use App\Actions\Personas\SetPersonaAvatarAction;
use App\Actions\Personas\UpdatePersonaAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Data\Personas\PersonaData;
use App\Enums\CommentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Personas\CannotManagePersonaException;
use App\Models\Comment;
use App\Models\MediaAsset;
use App\Models\PersonaProfile;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A Persona's account at the end of its life: anonymization turns it into a
 * tombstone like anyone's — its contribution kept, its avatar released — and
 * takes what Content Studio kept about it along with it, in the same
 * transaction. Nothing that raced the deletion brings any of it back.
 */
beforeEach(function () {
    Storage::fake('public');
});

function describedPersona(): User
{
    return PersonaProfile::factory()
        ->for(User::factory()->persona()->state(['display_name' => 'Max', 'bio' => 'Dogs, mostly.']), 'user')
        ->create(['character' => 'Grumpy but kind.', 'never_writes_about' => 'Politics'])
        ->user;
}

it('takes the persona\'s Content Studio profile with the account, keeping its contribution and releasing its avatar', function () {
    $persona = describedPersona();
    app(SetPersonaAvatarAction::class)->handle(User::factory()->admin()->create(), $persona, UploadedFile::fake()->image('face.jpg', 200, 200), PersonaData::of($persona, $persona->personaProfile)->fingerprint(), null);
    $avatar = $persona->fresh()->avatar_asset_id;
    $post = Post::factory()->published()->for($persona)->create();
    $comment = Comment::factory()->for($persona)->create(['status' => CommentStatus::Visible]);
    $vote = PostVote::factory()->create(['user_id' => $persona->id]);

    app(AnonymizeUserAccountAction::class)->execute($persona);

    expect(PersonaProfile::query()->where('user_id', $persona->id)->exists())->toBeFalse()
        ->and($persona->fresh())
        ->status->toBe(UserStatus::Deleted)
        ->role->toBe(UserRole::User)
        ->display_name->toBeNull()
        ->bio->toBeNull()
        ->avatar_asset_id->toBeNull()
        ->and(MediaAsset::withTrashed()->find($avatar)->trashed())->toBeTrue()
        ->and(Post::query()->whereKey($post->id)->exists())->toBeTrue()
        ->and(Comment::query()->whereKey($comment->id)->exists())->toBeTrue()
        ->and(PostVote::query()->whereKey($vote->id)->exists())->toBeTrue();
});

it('anonymizes a persona twice safely', function () {
    $persona = describedPersona();

    app(AnonymizeUserAccountAction::class)->execute($persona);
    $tombstone = $persona->fresh()->getAttributes();

    app(AnonymizeUserAccountAction::class)->execute($persona->fresh());

    expect($persona->fresh()->getAttributes())->toBe($tombstone)
        ->and(PersonaProfile::query()->exists())->toBeFalse();
});

it('takes no member\'s data it should not, a member having no profile', function () {
    $member = User::factory()->create();
    $persona = describedPersona();

    app(AnonymizeUserAccountAction::class)->execute($member);

    expect(PersonaProfile::query()->where('user_id', $persona->id)->exists())->toBeTrue();
});

it('writes nothing back into a persona deleted while its editor was open', function () {
    $persona = describedPersona();
    $drawn = PersonaData::of($persona, $persona->personaProfile);

    app(AnonymizeUserAccountAction::class)->execute(User::query()->findOrFail($persona->id));

    expect(fn () => app(UpdatePersonaAction::class)->handle(
        User::factory()->admin()->create(),
        $persona,
        new PersonaData(...[...get_object_vars($drawn), 'character' => 'Back from the dead']),
        $drawn->fingerprint(),
    ))->toThrow(fn (CannotManagePersonaException $exception) => expect($exception->isGone())->toBeTrue());

    expect(PersonaProfile::query()->exists())->toBeFalse()
        ->and($persona->fresh())->display_name->toBeNull()->bio->toBeNull()->status->toBe(UserStatus::Deleted);
});

it('leaves nothing of a save that landed just before the deletion', function () {
    $persona = describedPersona();
    $drawn = PersonaData::of($persona, $persona->personaProfile);

    app(UpdatePersonaAction::class)->handle(
        User::factory()->admin()->create(),
        $persona,
        new PersonaData(...[...get_object_vars($drawn), 'character' => 'Saved first']),
        $drawn->fingerprint(),
    );
    app(AnonymizeUserAccountAction::class)->execute(User::query()->findOrFail($persona->id));

    expect(PersonaProfile::query()->exists())->toBeFalse()
        ->and($persona->fresh()->display_name)->toBeNull();
});
