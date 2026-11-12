<?php

use App\Actions\Games\ChangeContentSetAction;
use App\Actions\Games\CreateContentSetAction;
use App\Actions\Games\SaveContentSetAction;
use App\Data\Games\ContentSetDraft;
use App\Enums\GameOptionSource;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\GameContentSet;
use App\Models\GameRound;
use App\Models\GameVersion;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('public');
});

function savedDraft(array $draft): ContentSetDraft
{
    return ContentSetDraft::of(GameContentSet::query()->with(['rounds.options', 'profiles'])->findOrFail($draft['set']->id));
}

it('saves rounds added, changed, moved and removed, with their options', function () {
    $draft = publishableGameDraft(2);
    $data = savedDraft($draft);
    $rounds = $data->rounds;
    $rounds[0]['question'] = 'Which beach?';
    $removed = $rounds[1]['id'];
    $rounds[1] = ContentSetDraft::newRound('nnew', 2);
    $rounds[1]['question'] = 'A new one?';
    $rounds[1]['options'][0]['post_id'] = gamePost(500, 10)->id;
    $rounds = array_reverse($rounds);

    app(SaveContentSetAction::class)->handle($draft['admin'], $draft['set'], new ContentSetDraft($data->scoring, $rounds, $data->profiles), $draft['set']->lock_version);

    $set = GameContentSet::query()->with('rounds.options')->find($draft['set']->id);

    expect($set->rounds->pluck('question')->all())->toBe(['A new one?', 'Which beach?'])
        ->and($set->rounds->first()->options)->toHaveCount(2)
        ->and(GameRound::query()->find($removed))->toBeNull()
        ->and($set->lock_version)->toBe(2);
});

it('refuses a save drawn before somebody else\'s', function () {
    $draft = publishableGameDraft(1);

    app(SaveContentSetAction::class)->handle($draft['admin'], $draft['set'], savedDraft($draft), $draft['set']->lock_version + 1);
})->throws(CannotManageGameException::class, 'Someone saved this content set after you opened it. Reload to see their changes.');

it('holds the draft to its shape and lengths', function (Closure $break, string $key) {
    $draft = publishableGameDraft(1);
    $data = savedDraft($draft)->toArray();
    $data = $break($data);

    expect(fn () => app(SaveContentSetAction::class)->handle($draft['admin'], $draft['set'], new ContentSetDraft($data['scoring'], $data['rounds'], $data['profiles']), $draft['set']->lock_version))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($key));
})->with([
    'a long question' => [function (array $d) {
        $d['rounds'][0]['question'] = str_repeat('a', 121);

        return $d;
    }, 'rounds.0.question'],
    'three options' => [function (array $d) {
        $d['rounds'][0]['options'][] = $d['rounds'][0]['options'][0];

        return $d;
    }, 'rounds.0.options'],
    'points out of range' => [function (array $d) {
        $d['scoring']['points_per_round'] = 11;

        return $d;
    }, 'scoring.points_per_round'],
    'an unknown badge' => [function (array $d) {
        $d['profiles'][0]['icon'] = 'skull';

        return $d;
    }, 'profiles.0.icon'],
    'a round of another set' => [function (array $d) {
        $d['rounds'][0]['id'] = GameRound::factory()->create()->id;

        return $d;
    }, 'rounds.0.id'],
]);

it('stores an uploaded picture with the save, and releases the one it replaces', function () {
    $draft = publishableGameDraft(1);
    $data = savedDraft($draft);
    $key = $data->rounds[0]['key'];

    app(SaveContentSetAction::class)->handle($draft['admin'], $draft['set'], $data, $draft['set']->lock_version, [
        "round:{$key}:1" => UploadedFile::fake()->image('b.jpg', 800, 600),
    ]);

    $option = GameRound::query()->sole()->options()->where('position', 1)->first();
    $first = MediaAsset::query()->find($option->asset_id);

    expect($option->source)->toBe(GameOptionSource::Upload)
        ->and($option->post_id)->toBeNull()
        ->and($first)->not->toBeNull();

    $data = savedDraft($draft);
    app(SaveContentSetAction::class)->handle($draft['admin'], $draft['set']->fresh(), $data, $draft['set']->fresh()->lock_version, [
        "round:{$key}:1" => UploadedFile::fake()->image('c.jpg', 800, 600),
    ]);

    expect($first->fresh()->trashed())->toBeTrue();
});

it('never lets an option take a picture from elsewhere', function () {
    $draft = publishableGameDraft(1);
    $data = savedDraft($draft)->toArray();
    $data['rounds'][0]['options'][1] = ['source' => 'upload', 'post_id' => null, 'asset_id' => MediaAsset::factory()->avatar()->create()->id, 'label' => '', 'is_correct' => false];

    app(SaveContentSetAction::class)->handle($draft['admin'], $draft['set'], new ContentSetDraft($data['scoring'], $data['rounds'], $data['profiles']), $draft['set']->lock_version);
})->throws(CannotManageGameException::class, 'A picture can only be uploaded here, not taken from elsewhere.');

it('gives a new profile a key from its name, and keeps a saved profile\'s key', function () {
    $draft = publishableGameDraft(3);
    $data = savedDraft($draft)->toArray();
    $data['profiles'][0]['name'] = 'Renamed Wild Card';
    $data['profiles'][] = ['id' => null, 'key' => 'new-x1', 'name' => 'Sharp Eye', 'description' => '', 'min' => 0, 'max' => 0, 'icon' => 'eye', 'share_text' => '', 'share_asset_id' => null];

    app(SaveContentSetAction::class)->handle($draft['admin'], $draft['set'], new ContentSetDraft($data['scoring'], $data['rounds'], $data['profiles']), $draft['set']->lock_version);

    expect(GameContentSet::query()->find($draft['set']->id)->profiles->pluck('key')->all())
        ->toBe(['wild-card', 'independent', 'sharp-eye', 'oracle', 'sharp-eye-2']);
});

it('moves a profile\'s key through one no profile can hold', function () {
    $draft = publishableGameDraft(1);
    [$first, $second] = $draft['set']->profiles()->orderBy('position')->get()->all();
    $second->forceFill(['key' => 'moving-'.$first->id])->save();
    $data = savedDraft($draft)->toArray();
    $data['profiles'][0]['key'] = 'renamed-key';

    app(SaveContentSetAction::class)->handle($draft['admin'], $draft['set'], new ContentSetDraft($data['scoring'], $data['rounds'], $data['profiles']), $draft['set']->lock_version);

    expect($draft['set']->profiles()->orderBy('position')->pluck('key')->all())->toBe(['renamed-key', 'moving-'.$first->id]);
});

it('renames or restores a set at the version its editor was drawn from, and refuses either over a newer save', function () {
    $draft = publishableGameDraft(1);
    $set = $draft['set'];
    $admin = $draft['admin'];

    app(ChangeContentSetAction::class)->rename($admin, $set, 'Renamed', 1);
    $set->forceFill(['archived_at' => now()])->save();

    expect(fn () => app(ChangeContentSetAction::class)->rename($admin, $set, 'Again', 1))
        ->toThrow(fn (CannotManageGameException $exception) => expect($exception->isStale())->toBeTrue());
    expect(fn () => app(ChangeContentSetAction::class)->restore($admin, $set, 1))
        ->toThrow(fn (CannotManageGameException $exception) => expect($exception->isStale())->toBeTrue());
    expect($set->fresh()->name)->toBe('Renamed')
        ->and($set->fresh()->isArchived())->toBeTrue();

    app(ChangeContentSetAction::class)->restore($admin, $set, 2);

    expect($set->fresh()->isArchived())->toBeFalse()
        ->and($set->fresh()->lock_version)->toBe(3);
});

it('copies a set with its rounds, options and profiles', function () {
    $draft = publishableGameDraft(2);

    $copy = app(CreateContentSetAction::class)->handle($draft['admin'], $draft['game'], 'Quick', $draft['set']->id);

    expect($copy->rounds()->count())->toBe(2)
        ->and($copy->rounds()->first()->options()->count())->toBe(2)
        ->and($copy->profiles()->count())->toBe($draft['set']->profiles()->count());
});

it('archives a set only while another remains, and deletes only one no version used', function () {
    $draft = publishableGameDraft(1);
    $admin = $draft['admin'];

    expect(fn () => app(ChangeContentSetAction::class)->archive($admin, $draft['set']))
        ->toThrow(CannotManageGameException::class);

    $second = app(CreateContentSetAction::class)->handle($admin, $draft['game'], 'Second');
    app(ChangeContentSetAction::class)->archive($admin, $draft['set']);

    expect($draft['set']->fresh()->isArchived())->toBeTrue();

    GameVersion::factory()->create(['game_id' => $draft['game']->id, 'content_set_id' => $second->id, 'flow_id' => $draft['flow']->id]);

    expect(fn () => app(ChangeContentSetAction::class)->delete($admin, $second))
        ->toThrow(CannotManageGameException::class, 'A published version was made from this set. Archive it instead.');

    app(ChangeContentSetAction::class)->delete($admin, $draft['set']->fresh());

    expect(GameContentSet::query()->find($draft['set']->id))->toBeNull();
});

it('lets only an administrator who may manage games change content', function () {
    $draft = publishableGameDraft(1);

    app(SaveContentSetAction::class)->handle(User::factory()->moderator()->create(), $draft['set'], savedDraft($draft), $draft['set']->lock_version);
})->throws(CannotManageGameException::class);
