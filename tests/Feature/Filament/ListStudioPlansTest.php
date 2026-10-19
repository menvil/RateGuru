<?php

use App\Enums\MediaVariantName;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\ListStudioPlans;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Models\MediaVariant;
use App\Models\StudioPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Publications & plans in Admin v2: what each row says — never more than the
 * plan holds — the figures it can count, the search and filters kept in the
 * address, 25 a page, and nothing a deleted author's account was called.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('says when there is no plan yet, and offers to create the first', function () {
    expect($this->get(StudioPlanResource::getUrl('index'))->assertOk()->getContent())
        ->toContain('<h1 id="rg-admin-studio-plans-title" class="rg-admin-page-header__title">Publications &amp; plans</h1>')
        ->toContain('Prepare publication drafts before reviewing and publishing them.')
        ->toContain('No publication plans yet')
        ->toContain('Create publication')
        ->not->toContain('fi-ta-table');
});

it('shows only figures it can count, and no states nothing makes yet', function () {
    StudioPlan::factory()->withImage()->authoredBy(User::factory()->persona()->create())->create();
    StudioPlan::factory()->create();

    Livewire::test(ListStudioPlans::class)
        ->assertViewHas('stats', ['total' => '2', 'drafts' => '2', 'withImage' => '1', 'withAuthor' => '1'])
        ->assertSee('Total plans')
        ->assertDontSee('Scheduled')
        ->assertDontSee('Published')
        ->assertDontSee('Comments');
});

it('draws each plan as it is: its title or that it has none, its author, its photo, when and its state', function () {
    Storage::fake('public');
    $persona = User::factory()->persona()->create(['username' => 'night_owl', 'name' => 'Night Owl', 'display_name' => 'Night Owl', 'status' => UserStatus::Limited]);
    $titled = StudioPlan::factory()->authoredBy($persona)->withImage()->create([
        'title' => 'A quiet harbour',
        'publication_mode' => StudioPlanPublicationMode::AtDate,
        'publish_at' => CarbonImmutable::parse('2019-05-06 07:08:00'),
    ]);
    $variant = MediaVariant::factory()->named(MediaVariantName::PostFeed640)->create(['media_asset_id' => $titled->image_asset_id, 'disk' => 'public', 'path' => 'post-images/feed-640.webp']);
    Storage::disk('public')->put($variant->path, 'small');
    $untitled = StudioPlan::factory()->create(['publication_mode' => StudioPlanPublicationMode::AtDate]);
    $now = StudioPlan::factory()->create(['title' => 'Right away']);

    $page = Livewire::test(ListStudioPlans::class);

    expect(listedRow($page, $titled))->toMatchArray([
        'title' => 'A quiet harbour',
        'untitled' => false,
        'editUrl' => EditStudioPlan::getUrl(['record' => $titled]),
        'hasImage' => true,
        'when' => '06 May 2019, 07:08',
        'state' => ['label' => 'Draft', 'tone' => 'outline', 'dot' => false],
        'draft' => true,
    ])->and(listedRow($page, $titled)['thumbnail'])->toEndWith('post-images/feed-640.webp')
        ->and(listedRow($page, $titled)['author'])->toMatchArray(['name' => 'Night Owl', 'username' => 'night_owl', 'problem' => 'restricted'])
        ->and(listedRow($page, $untitled))->toMatchArray(['title' => 'Untitled draft #'.$untitled->id, 'untitled' => true, 'author' => null, 'hasImage' => false, 'thumbnail' => null, 'when' => 'Date not selected'])
        ->and(listedRow($page, $now))->toMatchArray(['when' => 'When approved']);

    $page->assertSee('No author selected')
        ->assertSee('Untitled draft #'.$untitled->id)
        ->assertSee('When approved')
        ->assertSee('Date not selected');
});

it('names a deleted author as a deleted user, and nothing else of the account', function () {
    $persona = User::factory()->persona()->create(['username' => 'gone_owl', 'name' => 'Gone Owl', 'display_name' => 'Gone Owl']);
    $plan = StudioPlan::factory()->authoredBy($persona)->create();
    $persona->forceFill(['status' => UserStatus::Deleted, 'anonymized_at' => now(), 'email' => 'deleted-'.$persona->id.'@tombstone.invalid'])->save();

    $html = $this->get(StudioPlanResource::getUrl('index'))->assertOk()->getContent();

    expect(listedRow(Livewire::test(ListStudioPlans::class), $plan)['author'])->toMatchArray(['name' => 'Deleted user', 'username' => null, 'avatar' => null])
        ->and($html)->toContain('Deleted user')
        ->not->toContain('gone_owl')
        ->not->toContain('Gone Owl')
        ->not->toContain('tombstone.invalid');
});

it('offers a draft Edit and Delete draft, and a plan past its draft neither, saying why', function () {
    $draft = StudioPlan::factory()->create(['title' => 'Still a draft']);
    $approved = StudioPlan::factory()->status(StudioPlanStatus::Approved)->create(['title' => 'Approved already']);

    $page = Livewire::test(ListStudioPlans::class);

    expect(listedRow($page, $approved))->toMatchArray(['draft' => false, 'state' => ['label' => 'Approved', 'tone' => 'info', 'dot' => false]]);
    // Delete draft asks about the version the row shows.
    $page->assertSeeHtml('wire:click="askToDelete('.$draft->id.', 1)"')
        ->assertDontSeeHtml('wire:click="askToDelete('.$approved->id.',')
        ->assertSee('Only a draft can be deleted')
        ->assertDontSee('Unschedule')
        ->assertDontSee('View post');
});

it('searches the title, the id and the author, and narrows by state and photo, kept in the address', function () {
    $persona = User::factory()->persona()->create(['username' => 'night_owl']);
    $harbour = StudioPlan::factory()->withImage()->create(['title' => 'A quiet harbour']);
    $owl = StudioPlan::factory()->authoredBy($persona)->create(['title' => 'Owls at dusk']);
    $plain = StudioPlan::factory()->create(['title' => 'Plain']);
    $ids = fn ($page): array => collect($page->viewData('rows'))->pluck('id')->all();

    $page = Livewire::test(ListStudioPlans::class)->set('q', 'harbour');
    expect($ids($page))->toBe([$harbour->id]);

    $page->set('q', '#'.$plain->id);
    expect($ids($page))->toContain($plain->id);

    $page->set('q', 'night_owl');
    expect($ids($page))->toBe([$owl->id]);

    $page->set('q', '')->set('image', 'with');
    expect($ids($page))->toBe([$harbour->id]);

    $page->set('image', 'without')->set('state', 'draft');
    expect($ids($page))->toEqualCanonicalizing([$owl->id, $plain->id]);

    $this->get(StudioPlanResource::getUrl('index').'?q=harbour&image=with&state=draft')->assertOk()->assertSee('A quiet harbour')->assertDontSee('Owls at dusk');
});

it('drops what a link puts in the address that the list does not offer', function (array $query) {
    StudioPlan::factory()->create(['title' => 'Listed']);

    Livewire::withQueryParams($query)->test(ListStudioPlans::class)
        ->assertSet('state', '')
        ->assertSet('image', '')
        ->assertSet('pageNumber', 1)
        ->assertSee('Listed');
})->with([
    'a state it does not offer' => [['state' => 'approved']],
    'a made-up state' => [['state' => 'published']],
    'a made-up photo filter' => [['image' => 'maybe']],
    'a page that is no number' => [['page' => 'abc']],
    'a list instead of a value' => [['state' => ['draft'], 'image' => ['with']]],
]);

it('keeps a search to a hundred characters', function () {
    Livewire::test(ListStudioPlans::class)
        ->set('q', str_repeat('a', 500))
        ->assertSet('q', str_repeat('a', 100));
});

it('tells an empty section from an empty search, and clears the filters', function () {
    StudioPlan::factory()->create(['title' => 'Listed']);

    Livewire::test(ListStudioPlans::class)
        ->set('q', 'nothing like it')
        ->assertSee('No plans match')
        ->assertSee('Clear filters')
        ->call('clearFilters')
        ->assertSet('q', '')
        ->assertSee('Listed');
});

it('reads 25 plans a page, in an order that keeps each plan in its place', function () {
    $plans = StudioPlan::factory()->count(27)->create(['updated_at' => now()->subHour()]);

    $first = Livewire::test(ListStudioPlans::class);
    $second = Livewire::test(ListStudioPlans::class)->call('goToPage', 2);

    $firstIds = collect($first->viewData('rows'))->pluck('id');
    $secondIds = collect($second->viewData('rows'))->pluck('id');

    expect($firstIds)->toHaveCount(25)
        ->and($secondIds)->toHaveCount(2)
        ->and($firstIds->merge($secondIds)->all())->toBe($plans->pluck('id')->sortDesc()->values()->all());
});

it('shows the last page for a page past the end, and says so in the address', function () {
    StudioPlan::factory()->count(27)->create();

    Livewire::withQueryParams(['page' => 999])->test(ListStudioPlans::class)
        ->assertSet('pageNumber', 2)
        ->assertViewHas('rows', fn (array $rows): bool => count($rows) === 2);

    $this->get(StudioPlanResource::getUrl('index').'?page=999')->assertOk()->assertSee('27 plans');
});

it('starts a new search or filter from the first page', function () {
    StudioPlan::factory()->count(27)->create();

    Livewire::test(ListStudioPlans::class)
        ->call('goToPage', 2)
        ->set('image', 'without')
        ->assertSet('pageNumber', 1);
});

it('reads a page of plans in the same few queries however many it shows', function () {
    $persona = User::factory()->persona()->withAvatar()->create();
    StudioPlan::factory()->count(3)->withImage()->authoredBy($persona)->create();

    $count = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        Livewire::test(ListStudioPlans::class);

        return $queries;
    };

    $few = $count();

    StudioPlan::factory()->count(20)->withImage()->authoredBy(User::factory()->persona()->withAvatar()->create())->create();

    expect($count())->toBe($few);
});
