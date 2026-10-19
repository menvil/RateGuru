<?php

use App\Actions\Studio\CreateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserRole;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\StudioPlan;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * An administrator starts a publication plan: a Draft as incomplete as the
 * editor leaves it, its status, creator and version set by the server, its
 * author a living Persona or nobody — and nothing published, queued or
 * announced for it.
 */
it('starts an empty draft, made by the administrator, at its first version', function () {
    $admin = User::factory()->admin()->create();

    $plan = app(CreateStudioPlanAction::class)->handle($admin, new StudioPlanData);

    expect(StudioPlan::query()->findOrFail($plan->id))
        ->status->toBe(StudioPlanStatus::Draft)
        ->created_by_user_id->toBe($admin->id)
        ->lock_version->toBe(1)
        ->author_user_id->toBeNull()
        ->image_asset_id->toBeNull()
        ->title->toBeNull()
        ->description->toBeNull()
        ->source_url->toBeNull()
        ->category_id->toBeNull()
        ->tag_ids->toBe([])
        ->author_answer_option_ids->toBe([])
        ->publication_mode->toBe(StudioPlanPublicationMode::Now)
        ->publish_at->toBeNull();
});

it('keeps everything the editor sets, as it will be stored', function () {
    $admin = User::factory()->admin()->create();
    $persona = User::factory()->persona()->create();
    $category = Category::factory()->create();
    $tags = Tag::factory()->count(3)->create();
    $option = RatingOption::factory()->create();

    $plan = app(CreateStudioPlanAction::class)->handle($admin, new StudioPlanData(
        authorUserId: $persona->id,
        title: '  A quiet harbour  ',
        description: "  Boats at dawn.\n",
        sourceUrl: ' https://example.com/harbour ',
        categoryId: $category->id,
        tagIds: [(string) $tags[2]->id, $tags[0]->id, $tags[2]->id],
        authorAnswerOptionIds: [$option->id],
        publicationMode: StudioPlanPublicationMode::AtDate,
        publishAt: CarbonImmutable::parse('2019-05-06 07:08:09.654321', 'Europe/Berlin'),
    ));

    expect(StudioPlan::query()->findOrFail($plan->id))
        ->author_user_id->toBe($persona->id)
        ->title->toBe('A quiet harbour')
        ->description->toBe('Boats at dawn.')
        ->source_url->toBe('https://example.com/harbour')
        ->category_id->toBe($category->id)
        ->tag_ids->toBe([$tags[0]->id, $tags[2]->id])
        ->author_answer_option_ids->toBe([$option->id])
        ->publication_mode->toBe(StudioPlanPublicationMode::AtDate)
        // Kept in the application's time zone, to the second.
        ->and(StudioPlan::query()->findOrFail($plan->id)->publish_at->toDateTimeString())->toBe('2019-05-06 05:08:09');
});

it('keeps no date for a plan published at once, and no text for an empty one', function () {
    $plan = app(CreateStudioPlanAction::class)->handle(User::factory()->admin()->create(), new StudioPlanData(
        title: '   ',
        description: '',
        publicationMode: StudioPlanPublicationMode::Now,
        publishAt: CarbonImmutable::parse('2030-01-01 10:00:00'),
    ));

    expect($plan->fresh())->title->toBeNull()->description->toBeNull()->publish_at->toBeNull();
});

it('takes a limited, banned or shadowbanned persona as the author: a draft publishes nothing', function (string $state) {
    $persona = User::factory()->persona()->{$state}()->create();

    $plan = app(CreateStudioPlanAction::class)->handle(User::factory()->admin()->create(), new StudioPlanData(authorUserId: $persona->id));

    expect($plan->fresh()->author_user_id)->toBe($persona->id);
})->with(['limited', 'banned', 'shadowbanned']);

it('refuses an author that is no living persona, keeping nothing', function (Closure $author) {
    $admin = User::factory()->admin()->create();

    expect(fn () => app(CreateStudioPlanAction::class)->handle($admin, new StudioPlanData(authorUserId: $author($admin))))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('authorUserId'));

    expect(StudioPlan::query()->exists())->toBeFalse();
})->with([
    'a member' => [fn () => User::factory()->create()->id],
    'a moderator' => [fn () => User::factory()->moderator()->create()->id],
    'another administrator' => [fn () => User::factory()->admin()->create()->id],
    'the administrator themselves' => [fn (User $admin) => $admin->id],
    'a deleted persona' => [fn () => User::factory()->persona()->tombstoned()->create()->id],
    'an account that does not exist' => [fn () => 2_000_000_000],
]);

it('refuses an author who stopped being a persona after the check, on the locked row', function () {
    $admin = User::factory()->admin()->create();
    $persona = User::factory()->persona()->create();

    // Demoted after the rules passed and before the row is locked: what a
    // role change landing during the save looks like.
    $demoted = false;
    DB::beforeExecuting(function (string $sql) use (&$demoted, $persona): void {
        if (! $demoted && str_contains(strtolower($sql), 'for update')) {
            $demoted = true;
            DB::table('users')->where('id', $persona->id)->update(['role' => UserRole::User->value]);
        }
    });

    expect(fn () => app(CreateStudioPlanAction::class)->handle($admin, new StudioPlanData(authorUserId: $persona->id)))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->isAuthorUnavailable())->toBeTrue());

    expect(StudioPlan::query()->exists())->toBeFalse();
})->skip(
    fn (): bool => config('database.default') === 'sqlite',
    'SQLite takes no row locks — `lockForUpdate()` adds nothing to its SQL — so there is no locked read to land before.',
);

it('lets nobody but an active administrator start a plan', function (Closure $user) {
    expect(fn () => app(CreateStudioPlanAction::class)->handle($user(), new StudioPlanData(title: 'Not mine')))
        ->toThrow(CannotManageStudioPlanException::class);

    expect(StudioPlan::query()->exists())->toBeFalse();
})->with([
    'a moderator' => [fn () => User::factory()->moderator()->create()],
    'a member' => [fn () => User::factory()->create()],
    'a persona' => [fn () => User::factory()->persona()->create()],
    'a limited administrator' => [fn () => User::factory()->admin()->limited()->create()],
    'a banned administrator' => [fn () => User::factory()->admin()->banned()->create()],
    'a deleted administrator' => [fn () => User::factory()->admin()->tombstoned()->create()],
]);

it('asks again on the administrator\'s locked row, not the copy it was handed', function () {
    $admin = User::factory()->admin()->create();
    User::query()->whereKey($admin->id)->firstOrFail()->forceFill(['status' => 'banned'])->save();

    expect(fn () => app(CreateStudioPlanAction::class)->handle($admin, new StudioPlanData))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->isNotAllowed())->toBeTrue());

    expect(StudioPlan::query()->exists())->toBeFalse();
});

it('publishes, queues and announces nothing', function () {
    Queue::fake();
    Notification::fake();
    $persona = User::factory()->persona()->create();

    app(CreateStudioPlanAction::class)->handle(User::factory()->admin()->create(), new StudioPlanData(
        authorUserId: $persona->id,
        title: 'Not a post',
        publicationMode: StudioPlanPublicationMode::AtDate,
        publishAt: CarbonImmutable::parse('2020-01-01 00:00:00'),
    ));

    expect(Post::query()->withTrashed()->exists())->toBeFalse()
        ->and(MediaAsset::query()->withTrashed()->exists())->toBeFalse();
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
});

it('has room for nothing the server sets', function () {
    $fields = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(StudioPlanData::class, '__construct'))->getParameters(),
    );

    expect($fields)->toBe(['authorUserId', 'title', 'description', 'sourceUrl', 'categoryId', 'tagIds', 'authorAnswerOptionIds', 'publicationMode', 'publishAt']);
});

it('holds whatever a draft has to the bounds of the post it becomes', function (Closure $data, string $field) {
    expect(fn () => app(CreateStudioPlanAction::class)->handle(User::factory()->admin()->create(), $data()))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($field));

    expect(StudioPlan::query()->exists())->toBeFalse();
})->with([
    'a title too short' => [fn () => new StudioPlanData(title: 'ab'), 'title'],
    'a title too long' => [fn () => new StudioPlanData(title: str_repeat('a', 121)), 'title'],
    'a description too long' => [fn () => new StudioPlanData(description: str_repeat('a', 2001)), 'description'],
    'a source that is no address' => [fn () => new StudioPlanData(sourceUrl: 'not an address'), 'sourceUrl'],
    'a source address too long' => [fn () => new StudioPlanData(sourceUrl: 'https://example.com/'.str_repeat('a', 2029)), 'sourceUrl'],
    'a category that does not exist' => [fn () => new StudioPlanData(categoryId: 2_000_000_000), 'categoryId'],
    'more than ten tags' => [fn () => new StudioPlanData(tagIds: Tag::factory()->count(11)->create()->modelKeys()), 'tagIds'],
    'a tag that does not exist' => [fn () => new StudioPlanData(tagIds: [2_000_000_000]), 'tagIds.0'],
    'a tag id of zero' => [fn () => new StudioPlanData(tagIds: [0]), 'tagIds.0'],
    'a negative tag id' => [fn () => new StudioPlanData(tagIds: [-3]), 'tagIds.0'],
    'a tag that is a word' => [fn () => new StudioPlanData(tagIds: ['travel']), 'tagIds.0'],
    'a tag that is true' => [fn () => new StudioPlanData(tagIds: [true]), 'tagIds.0'],
    'a tag that is a fraction' => [fn () => new StudioPlanData(tagIds: [1.5]), 'tagIds.0'],
    'a tag that is a list' => [fn () => new StudioPlanData(tagIds: [[1]]), 'tagIds.0'],
    'tags given as an object' => [fn () => new StudioPlanData(tagIds: ['first' => Tag::factory()->create()->id]), 'tagIds'],
    'an answer option that does not exist' => [fn () => new StudioPlanData(authorAnswerOptionIds: [2_000_000_000]), 'authorAnswerOptionIds.0'],
    'an answer that is a list' => [fn () => new StudioPlanData(authorAnswerOptionIds: [['id' => 1]]), 'authorAnswerOptionIds.0'],
    'two answers in one rating group' => [function () {
        $group = RatingGroup::factory()->create();

        return new StudioPlanData(authorAnswerOptionIds: RatingOption::factory()->count(2)->create(['rating_group_id' => $group->id])->modelKeys());
    }, 'authorAnswerOptionIds'],
    'a date before what a post can hold' => [fn () => new StudioPlanData(publicationMode: StudioPlanPublicationMode::AtDate, publishAt: CarbonImmutable::parse('1969-12-31 00:00:00')), 'publishAt'],
    'a date after what a post can hold' => [fn () => new StudioPlanData(publicationMode: StudioPlanPublicationMode::AtDate, publishAt: CarbonImmutable::parse('2040-01-01 00:00:00')), 'publishAt'],
]);

it('takes one answer from each of several rating groups', function () {
    $answers = [RatingOption::factory()->create()->id, RatingOption::factory()->create()->id];

    $plan = app(CreateStudioPlanAction::class)->handle(User::factory()->admin()->create(), new StudioPlanData(authorAnswerOptionIds: $answers));

    expect($plan->fresh()->author_answer_option_ids)->toBe($answers);
});
