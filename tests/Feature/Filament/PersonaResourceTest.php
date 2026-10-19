<?php

use App\Actions\Personas\SetPersonaAvatarAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Data\Personas\PersonaData;
use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Personas\Pages\CreatePersona;
use App\Filament\Resources\Personas\Pages\EditPersona;
use App\Filament\Resources\Personas\Pages\ListPersonas;
use App\Filament\Resources\Personas\PersonaResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Livewire\Admin\GlobalSearch;
use App\Models\MediaAsset;
use App\Models\PersonaProfile;
use App\Models\User;
use App\Queries\Personas\PersonasQuery;
use App\Services\Media\GdImageIngestor;
use App\Services\Media\ImageIngestor;
use App\Services\Media\ImageIngestPolicy;
use App\Services\Media\ImageInput;
use App\Services\Media\NormalizedImage;
use App\Support\Profile\ProfileValidationRules;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Content Studio's Personas in Admin v2: who may open them, the list and
 * what narrows it, a new Persona and one Persona's editor saved from the top
 * bar — refusing a draft drawn before somebody else's save — and the ways in
 * from Users and from the global search.
 */
function studioPersona(array $account = [], array $profile = []): User
{
    return PersonaProfile::factory()
        ->for(User::factory()->persona()->state($account), 'user')
        ->create($profile)
        ->user;
}

it('sits alone in the Content Studio section, with the list, a new persona and a persona\'s editor', function () {
    expect(PersonaResource::getNavigationGroup())->toBe(AdminNavigationGroup::CONTENT_STUDIO)
        ->and(PersonaResource::getNavigationLabel())->toBe('Personas')
        ->and(array_keys(PersonaResource::getPages()))->toBe(['index', 'create', 'edit'])
        ->and(PersonaResource::getUrl('index'))->toEndWith('/admin/personas');
});

it('opens every page for an active administrator, and for nobody else', function (Closure $user, int $status) {
    $persona = studioPersona();
    $this->actingAs($user());

    $this->get(PersonaResource::getUrl('index'))->assertStatus($status);
    $this->get(PersonaResource::getUrl('create'))->assertStatus($status);
    $this->get(PersonaResource::getUrl('edit', ['record' => $persona]))->assertStatus($status);
})->with([
    'an administrator' => [fn () => User::factory()->admin()->create(), 200],
    'a moderator' => [fn () => User::factory()->moderator()->create(), 403],
    'a member' => [fn () => User::factory()->create(), 403],
    'a banned administrator' => [fn () => User::factory()->admin()->banned()->create(), 403],
]);

it('lets a moderator reach no persona page, nor save through Livewire on one opened before', function () {
    $persona = studioPersona(['name' => 'Kept', 'display_name' => 'Kept']);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $page = Livewire::test(EditPersona::class, ['record' => $persona->id])->set('displayName', 'Taken over');

    // The same session, demoted since the page was drawn.
    $admin->forceFill(['role' => UserRole::Moderator])->save();

    $page->call('save')->assertForbidden();

    expect($persona->fresh()->display_name)->toBe('Kept');
});

it('draws the list in Admin v2, every column read on the server', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona(
        ['username' => 'night_owl', 'name' => 'Night Owl', 'display_name' => 'Night Owl', 'status' => UserStatus::Limited],
        ['short_description' => 'Insomniac with opinions', 'speech_style' => 'Long sentences', 'emoji_frequency' => PersonaEmojiFrequency::Frequent, 'engagement' => PersonaEngagement::High, 'max_replies_per_day' => 12],
    );

    expect($this->get(PersonaResource::getUrl('index'))->assertOk()->getContent())
        ->toContain('<h1 id="rg-admin-personas-title" class="rg-admin-page-header__title">Personas</h1>')
        ->toContain('Night Owl')
        ->toContain('Create persona')
        ->not->toContain('fi-ta-table');

    expect(listedRow(Livewire::test(ListPersonas::class), $persona))->toMatchArray([
        'name' => 'Night Owl',
        'username' => 'night_owl',
        'short' => 'Insomniac with opinions',
        'style' => 'Long sentences',
        'emoji' => 'Frequent',
        'status' => ['label' => 'Limited', 'tone' => 'warning', 'dot' => false],
        'engagement' => 'High',
        'replies' => 12,
        'avatar' => null,
        'editUrl' => EditPersona::getUrl(['record' => $persona]),
        'profileUrl' => route('profile.show', ['username' => 'night_owl']),
        'usersUrl' => UserResource::getUrl('edit', ['record' => $persona]),
    ]);
});

it('shows only figures it can count now', function () {
    $this->actingAs(User::factory()->admin()->create());
    studioPersona([], ['engagement' => PersonaEngagement::Normal]);
    studioPersona(['status' => UserStatus::Banned]);

    Livewire::test(ListPersonas::class)
        ->assertViewHas('stats', ['total' => '2', 'active' => '1', 'restricted' => '1', 'replying' => '1'])
        ->assertDontSee('7 days')
        ->assertDontSee('Plans');
});

it('narrows by search, status and engagement, kept in the address', function () {
    $this->actingAs(User::factory()->admin()->create());
    $owl = studioPersona(['username' => 'night_owl'], ['engagement' => PersonaEngagement::High]);
    $limited = studioPersona(['status' => UserStatus::Limited], ['character' => 'Grumpy']);

    $page = Livewire::withQueryParams(['status' => 'limited'])->test(ListPersonas::class);

    expect(collect($page->viewData('rows'))->pluck('id')->all())->toBe([$limited->id]);

    $page->set('status', '')->set('engagement', 'high');
    expect(collect($page->viewData('rows'))->pluck('id')->all())->toBe([$owl->id]);

    $page->set('engagement', '')->set('q', 'grumpy');
    expect(collect($page->viewData('rows'))->pluck('id')->all())->toBe([$limited->id]);

    $page->call('clearFilters');
    expect(collect($page->viewData('rows'))->pluck('id')->all())->toEqualCanonicalizing([$owl->id, $limited->id]);
});

it('drops what a link puts in the address that the list does not offer', function (array $query) {
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona();

    $page = Livewire::withQueryParams($query)->test(ListPersonas::class)->assertOk();

    expect($page->get('status'))->toBe('')
        ->and($page->get('engagement'))->toBe('')
        ->and($page->get('pageNumber'))->toBe(1)
        ->and(collect($page->viewData('rows'))->pluck('id')->all())->toBe([$persona->id]);
})->with([
    'a deleted status' => [['status' => 'deleted']],
    'an unknown status' => [['status' => 'sleeping']],
    'an unknown engagement' => [['engagement' => 'always']],
    'a status as a list' => [['status' => ['active']]],
    'a page that is no number' => [['page' => 'last']],
]);

it('pages 25 at a time', function () {
    $this->actingAs(User::factory()->admin()->create());
    User::factory()->persona()->count(PersonasQuery::PER_PAGE + 2)->create();

    $page = Livewire::test(ListPersonas::class);

    expect($page->viewData('rows'))->toHaveCount(PersonasQuery::PER_PAGE);

    $page->call('goToPage', 2);
    expect($page->viewData('rows'))->toHaveCount(2);
});

it('shows the last page for a page past the end', function () {
    $this->actingAs(User::factory()->admin()->create());
    User::factory()->persona()->count(PersonasQuery::PER_PAGE + 2)->create();

    $page = Livewire::withQueryParams(['page' => 99])->test(ListPersonas::class);

    expect($page->viewData('rows'))->toHaveCount(2)
        ->and($page->viewData('paginator')->currentPage())->toBe(2)
        ->and($page->get('pageNumber'))->toBe(2);

    $page->assertSee('27 personas')->assertDontSee('No personas match');
});

it('moves back to the last page left when personas go after the list was drawn', function () {
    $this->actingAs(User::factory()->admin()->create());
    $personas = User::factory()->persona()->count(PersonasQuery::PER_PAGE + 2)->create();

    $page = Livewire::withQueryParams(['page' => 2])->test(ListPersonas::class);
    expect($page->viewData('rows'))->toHaveCount(2);

    $personas->take(3)->each(fn (User $persona) => app(AnonymizeUserAccountAction::class)->execute($persona));
    $page->call('$refresh');

    expect($page->viewData('rows'))->toHaveCount(PersonasQuery::PER_PAGE - 1)
        ->and($page->viewData('paginator')->currentPage())->toBe(1)
        ->and($page->get('pageNumber'))->toBe(1);

    // Every one gone: the section is empty, on its first page.
    $personas->skip(3)->each(fn (User $persona) => app(AnonymizeUserAccountAction::class)->execute($persona));
    $page->call('goToPage', 2)->assertSee('No personas yet');

    expect($page->get('pageNumber'))->toBe(1);
});

it('tells an empty section from an empty search', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListPersonas::class)
        ->assertSee('No personas yet')
        ->assertSeeHtml('href="'.e(CreatePersona::getUrl()).'"');

    studioPersona();

    Livewire::withQueryParams(['q' => 'nobody-like-this'])->test(ListPersonas::class)
        ->assertSee('No personas match')
        ->assertSee('Clear filters');
});

it('draws a new persona\'s page with Create persona in the top bar', function () {
    $this->actingAs(User::factory()->admin()->create());

    expect($this->get(PersonaResource::getUrl('create'))->assertOk()->getContent())
        ->toContain('<h1 id="rg-admin-persona-title" class="rg-admin-page-header__title">New persona</h1>')
        ->toContain("\$dispatch('save-persona')")
        ->toMatch('/save-persona[^<]*>(?:\s|<!--.*?-->)*Create persona\s*</')
        ->toContain('Short description')
        ->toContain('Never writes about')
        ->toContain('Max replies a day')
        ->not->toContain('City')
        ->not->toContain('Country')
        ->not->toContain('Generate avatar')
        ->not->toContain('Try it')
        ->not->toContain('Active from');
});

it('creates a persona and opens its editor', function () {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(CreatePersona::class)
        ->set('displayName', 'Night Owl')
        ->set('username', 'night_owl')
        ->set('bio', 'Up late.')
        ->set('shortDescription', 'Insomniac')
        ->set('character', 'Dry.')
        ->set('speechStyle', 'Long sentences.')
        ->set('topics', 'Cats')
        ->set('neverWritesAbout', 'Medicine')
        ->set('emojiFrequency', 'occasional')
        ->set('engagement', 'low')
        ->set('maxRepliesPerDay', '5')
        ->set('photo', UploadedFile::fake()->image('owl.jpg', 300, 300))
        ->dispatch('save-persona')
        ->assertHasNoErrors()
        ->assertRedirect(EditPersona::getUrl(['record' => User::query()->where('username', 'night_owl')->value('id')]));

    $persona = User::query()->where('username', 'night_owl')->firstOrFail();

    expect($persona)
        ->role->toBe(UserRole::Persona)
        ->status->toBe(UserStatus::Active)
        ->display_name->toBe('Night Owl')
        ->bio->toBe('Up late.')
        ->and($persona->personaProfile)
        ->engagement->toBe(PersonaEngagement::Low)
        ->max_replies_per_day->toBe(5)
        ->created_by_user_id->toBe($admin->id)
        ->and(MediaAsset::query()->findOrFail($persona->avatar_asset_id)->owner_user_id)->toBe($persona->id)
        ->and(session('rg-admin-toast'))->toBe(['message' => 'Night Owl created', 'tone' => 'success']);
});

it('makes one persona however often Save reaches the page', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreatePersona::class)
        ->set('displayName', 'Only Once')
        ->call('save')
        ->call('save')
        ->call('save');

    expect(User::query()->where('role', UserRole::Persona)->count())->toBe(1);
});

it('says what is wrong by each field, creating nothing', function () {
    $this->actingAs(User::factory()->admin()->create());
    User::factory()->create(['username' => 'taken_name']);

    Livewire::test(CreatePersona::class)
        ->set('displayName', '')
        ->set('username', 'Not Valid')
        ->set('maxRepliesPerDay', '51')
        ->set('character', str_repeat('a', 2001))
        ->call('save')
        ->assertHasErrors(['displayName' => 'required', 'username' => 'regex', 'maxRepliesPerDay', 'character' => 'max'])
        ->assertSee('Enter a display name.')
        ->assertSee('Use lowercase letters, numbers and underscores.')
        ->assertSee('Enter a whole number from 0 to 50.')
        ->set('displayName', 'Taken')
        ->set('username', 'taken_name')
        ->set('maxRepliesPerDay', '3')
        ->set('character', 'ok')
        ->call('save')
        ->assertHasErrors(['username' => 'unique'])
        ->assertNoRedirect();

    expect(User::query()->where('role', UserRole::Persona)->exists())->toBeFalse();
});

it('checks a new persona as it will be stored, so spaces around a name, a username or a text refuse nothing', function () {
    $this->actingAs(User::factory()->admin()->create());
    $name = str_repeat('Owl ', 20);

    Livewire::test(CreatePersona::class)
        ->set('displayName', '  '.str_replace(' ', '   ', $name))
        ->set('username', ' night_owl ')
        ->set('bio', '  '.str_repeat('b', ProfileValidationRules::BIO_LIMIT).'  ')
        ->set('shortDescription', "\n".str_repeat('s', PersonaProfile::SHORT_DESCRIPTION_LIMIT)."\n")
        ->set('character', str_repeat('c', PersonaProfile::NOTES_LIMIT)."\n\n")
        ->dispatch('save-persona')
        ->assertHasNoErrors();

    $persona = User::query()->where('username', 'night_owl')->firstOrFail();

    expect($persona)
        ->display_name->toBe(trim($name))
        ->bio->toBe(str_repeat('b', ProfileValidationRules::BIO_LIMIT))
        ->and($persona->personaProfile)
        ->short_description->toBe(str_repeat('s', PersonaProfile::SHORT_DESCRIPTION_LIMIT))
        ->character->toBe(str_repeat('c', PersonaProfile::NOTES_LIMIT));
});

it('checks an edit as it will be stored, holding each text to its limit without the spaces around it', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona(['username' => 'night_owl', 'display_name' => 'Night Owl']);

    Livewire::test(EditPersona::class, ['record' => $persona->id])
        ->set('displayName', ' Night   Owl ')
        ->set('username', ' night_owl ')
        ->set('speechStyle', ' '.str_repeat('s', PersonaProfile::NOTES_LIMIT).' ')
        ->set('topics', "\t".str_repeat('t', PersonaProfile::NOTES_LIMIT))
        ->set('neverWritesAbout', str_repeat('n', PersonaProfile::NOTES_LIMIT)."\n")
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('displayName', 'Night Owl')
        ->assertSet('username', 'night_owl');

    expect($persona->fresh()->personaProfile)
        ->speech_style->toBe(str_repeat('s', PersonaProfile::NOTES_LIMIT))
        ->topics->toBe(str_repeat('t', PersonaProfile::NOTES_LIMIT))
        ->never_writes_about->toBe(str_repeat('n', PersonaProfile::NOTES_LIMIT));

    // What is too long once trimmed is still refused, as the action refuses it.
    Livewire::test(EditPersona::class, ['record' => $persona->id])
        ->set('shortDescription', ' '.str_repeat('s', PersonaProfile::SHORT_DESCRIPTION_LIMIT + 1).' ')
        ->call('save')
        ->assertHasErrors(['shortDescription' => 'max']);
});

it('takes no role, status, address or trust from a request', function (string $property, mixed $value) {
    $this->actingAs(User::factory()->admin()->create());

    expect(fn () => Livewire::test(CreatePersona::class)->set('displayName', 'Forged')->set($property, $value))
        ->toThrow(Exception::class);

    expect(User::query()->where('role', UserRole::Persona)->exists())->toBeFalse();
})->with([
    'a role' => ['role', 'admin'],
    'a status' => ['status', 'active'],
    'an address' => ['email', 'someone@example.com'],
    'trust' => ['trust_level', 99],
    'a password' => ['password', 'secret123'],
    'what it made' => ['createdId', 1],
]);

it('puts the saved values back on Discard', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona(['name' => 'Kept', 'display_name' => 'Kept'], ['character' => 'Kept']);

    Livewire::test(EditPersona::class, ['record' => $persona->id])
        ->set('displayName', 'Changed')
        ->set('character', 'Changed')
        ->set('engagement', 'high')
        ->dispatch('discard-persona')
        ->assertSet('displayName', 'Kept')
        ->assertSet('character', 'Kept')
        ->assertSet('engagement', 'off');
});

it('draws a persona\'s editor with Save changes in the top bar and its account beside it', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona(['username' => 'night_owl', 'name' => 'Night Owl', 'display_name' => 'Night Owl'], ['character' => 'Grumpy but kind']);

    expect($this->get(PersonaResource::getUrl('edit', ['record' => $persona]))->assertOk()->getContent())
        ->toContain('<h1 id="rg-admin-persona-title" class="rg-admin-page-header__title">Night Owl</h1>')
        ->toContain("\$dispatch('save-persona')")
        ->toMatch('/save-persona[^<]*>(?:\s|<!--.*?-->)*Save changes\s*</')
        ->toContain('Grumpy but kind')
        ->toContain('href="'.route('profile.show', ['username' => 'night_owl']).'"')
        ->toContain('href="'.UserResource::getUrl('edit', ['record' => $persona]).'"')
        ->toContain('Never signs in');
});

it('saves the editor and stays on it, the saved values drawn again', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona(['username' => 'old_name', 'name' => 'Old', 'display_name' => 'Old']);

    Livewire::test(EditPersona::class, ['record' => $persona->id])
        ->set('displayName', 'New Name')
        ->set('username', 'new_name')
        ->set('engagement', 'normal')
        ->set('maxRepliesPerDay', '9')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertDispatched('rg-admin-toast', message: 'Changes to New Name saved', tone: 'success')
        ->assertSet('saved.displayName', 'New Name')
        ->assertSet('saved.username', 'new_name');

    expect($persona->fresh())->display_name->toBe('New Name')->username->toBe('new_name')
        ->and($persona->fresh()->personaProfile)->engagement->toBe(PersonaEngagement::Normal)->max_replies_per_day->toBe(9);

    $this->get('/u/new_name')->assertOk()->assertSee('New Name');
    $this->get('/u/old_name')->assertNotFound();
});

it('gives the persona a new photo with the rest of the draft, its own save no conflict with itself', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona(['display_name' => 'Night Owl'], ['character' => 'Dry']);

    $page = Livewire::test(EditPersona::class, ['record' => $persona->id])
        ->set('character', 'Warmer')
        ->set('photo', UploadedFile::fake()->image('new.jpg', 200, 200))
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Changes to Night Owl saved', tone: 'success')
        ->assertSet('stale', false)
        ->assertSet('photo', null);

    $persona->refresh();

    expect($persona->personaProfile->character)->toBe('Warmer')
        ->and(MediaAsset::query()->findOrFail($persona->avatar_asset_id)->owner_user_id)->toBe($persona->id)
        ->and($page->get('avatarAssetId'))->toBe($persona->avatar_asset_id);

    // Drawn again from what was saved, so the next photo is no conflict either.
    $page->set('photo', UploadedFile::fake()->image('newer.jpg', 200, 200))->call('save')->assertHasNoErrors();

    expect($persona->fresh()->avatar_asset_id)->not->toBe($persona->avatar_asset_id);
});

it('refuses a photo alone chosen before somebody else\'s save, keeping their avatar and no file', function (Closure $meanwhile) {
    Storage::fake('public');
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona(['display_name' => 'Night Owl'], ['character' => 'Dry']);

    $page = Livewire::test(EditPersona::class, ['record' => $persona->id])
        ->set('photo', UploadedFile::fake()->image('mine.jpg', 200, 200));

    $meanwhile($persona);
    $theirs = $persona->fresh()->avatar_asset_id;
    $files = Storage::disk('public')->allFiles();
    $assets = MediaAsset::withTrashed()->count();

    $page->call('save')
        ->assertDispatched('rg-admin-toast', message: 'Someone saved this persona after you opened it, so nothing was saved.', tone: 'error')
        ->assertSet('stale', true);

    expect($persona->fresh()->avatar_asset_id)->toBe($theirs)
        ->and(MediaAsset::withTrashed()->count())->toBe($assets)
        ->and(Storage::disk('public')->allFiles())->toBe($files);

    $page->dispatch('reload-persona')->assertSet('stale', false)->assertSet('photo', null)->assertSet('avatarAssetId', $theirs);
})->with([
    'their profile' => [fn (User $persona) => PersonaProfile::query()->where('user_id', $persona->id)->update(['character' => 'Their edit'])],
    'their photo' => [fn (User $persona) => app(SetPersonaAvatarAction::class)->handle(User::factory()->admin()->create(), $persona, UploadedFile::fake()->image('theirs.jpg', 200, 200), PersonaData::of($persona, $persona->personaProfile)->fingerprint(), null)],
]);

it('keeps the changes but not the photo when somebody saves the persona in between, and says so', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona(['display_name' => 'Night Owl'], ['character' => 'Dry', 'topics' => 'Cats']);

    // Somebody else's save lands while the photo is being read: after this
    // page's changes were saved, before its photo is.
    app()->instance(ImageIngestor::class, new class($persona) implements ImageIngestor
    {
        public function __construct(private readonly User $persona) {}

        public function ingest(ImageInput $input, ImageIngestPolicy $policy): NormalizedImage
        {
            PersonaProfile::query()->where('user_id', $this->persona->id)->update(['topics' => 'Dogs']);

            return app(GdImageIngestor::class)->ingest($input, $policy);
        }
    });

    Livewire::test(EditPersona::class, ['record' => $persona->id])
        ->set('character', 'Warmer')
        ->set('photo', UploadedFile::fake()->image('mine.jpg', 200, 200))
        ->call('save')
        ->assertDispatched('rg-admin-toast', message: 'Changes saved, but someone else saved this persona meanwhile, so the photo wasn’t.', tone: 'error')
        ->assertSet('stale', true)
        ->assertSet('character', 'Warmer');

    expect($persona->fresh()->personaProfile)->character->toBe('Warmer')->topics->toBe('Dogs')
        ->and($persona->fresh()->avatar_asset_id)->toBeNull()
        ->and(MediaAsset::withTrashed()->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('refuses a draft drawn before somebody else\'s save, and Reload draws what they saved', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona(['name' => 'Mine', 'display_name' => 'Mine'], ['character' => 'Original']);

    $page = Livewire::test(EditPersona::class, ['record' => $persona->id])->set('character', 'My edit');

    PersonaProfile::query()->where('user_id', $persona->id)->update(['character' => 'Their edit']);

    $page->call('save')
        ->assertDispatched('rg-admin-toast', message: 'Someone saved this persona after you opened it, so nothing was saved.', tone: 'error')
        ->assertSet('stale', true)
        ->assertSee('Someone saved this persona after you opened it.');

    expect($persona->fresh()->personaProfile->character)->toBe('Their edit');

    $page->dispatch('reload-persona')->assertSet('stale', false)->assertSet('character', 'Their edit');
});

it('saves nothing to a persona deleted since its editor was drawn, and says so', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona();

    $page = Livewire::test(EditPersona::class, ['record' => $persona->id])->set('character', 'Too late');

    User::query()->whereKey($persona->id)->update(['status' => UserStatus::Deleted]);

    $page->call('save')
        ->assertDispatched('rg-admin-toast', message: 'This persona can’t be edited any more, so nothing was saved.', tone: 'error')
        ->assertSet('gone', true);

    expect($persona->fresh()->personaProfile->character)->not->toBe('Too late');
});

it('says what is wrong by each field on the editor, saving nothing', function () {
    $this->actingAs(User::factory()->admin()->create());
    User::factory()->create(['username' => 'taken_name']);
    $persona = studioPersona(['name' => 'Kept', 'display_name' => 'Kept']);

    Livewire::test(EditPersona::class, ['record' => $persona->id])
        ->set('displayName', '')
        ->set('username', 'taken_name')
        ->set('engagement', 'always')
        ->call('save')
        ->assertHasErrors(['displayName' => 'required', 'username' => 'unique', 'engagement']);

    expect($persona->fresh()->display_name)->toBe('Kept');
});

it('keeps a username older rules allowed when it is not changed', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona(['username' => 'old.style.name']);

    Livewire::test(EditPersona::class, ['record' => $persona->id])
        ->set('username', ' old.style.name ')
        ->set('character', 'Saved anyway')
        ->call('save')
        ->assertHasNoErrors();

    expect($persona->fresh())->username->toBe('old.style.name')
        ->and($persona->fresh()->personaProfile->character)->toBe('Saved anyway');
});

it('opens no editor for an account that is no persona, nor for a deleted one', function (Closure $account) {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(PersonaResource::getUrl('edit', ['record' => $account()]))->assertNotFound();
})->with([
    'a member' => fn () => User::factory()->create(),
    'an administrator' => fn () => User::factory()->admin()->create(),
    'a deleted persona' => fn () => User::factory()->persona()->tombstoned()->create(),
]);

it('leads from a persona\'s page in Users to its editor', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona();

    expect($this->get(EditUser::getUrl(['record' => $persona]))->assertOk()->getContent())
        ->toContain('href="'.EditPersona::getUrl(['record' => $persona]).'"')
        ->toContain('Edit in Content Studio');
});

it('leads an administrator\'s search result for a persona to its editor, and a member\'s to their page', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->create());
    $persona = studioPersona(['username' => 'searchable_owl']);
    $member = User::factory()->create(['username' => 'searchable_member']);

    expect(UserResource::getGlobalSearchResultUrl($persona))->toBe(EditPersona::getUrl(['record' => $persona]))
        ->and(UserResource::getGlobalSearchResultUrl($member))->toBe(EditUser::getUrl(['record' => $member]));

    Livewire::test(GlobalSearch::class)
        ->set('search', 'searchable_owl')
        ->assertSeeHtml('href="'.e(EditPersona::getUrl(['record' => $persona])).'"');
});

it('leads a moderator\'s search result for a persona where it always did', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->moderator()->create());
    $persona = studioPersona(['username' => 'searchable_owl']);

    expect(UserResource::getGlobalSearchResultUrl($persona))->toBe(UserResource::getUrl('index', ['q' => 'searchable_owl']));
});
