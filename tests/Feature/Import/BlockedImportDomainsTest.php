<?php

use App\Actions\Import\StoreImportedImageAction;
use App\Exceptions\Import\ImportDomainBlockedException;
use App\Filament\Pages\ProjectSettingsPage;
use App\Livewire\Feed\UploadPostForm;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Import\BlockedImportDomains;
use App\Support\Import\Dns\HostResolver;
use App\Support\Import\ImportHttpTransport;
use App\Support\Import\ImportTransportResponse;
use App\Support\Import\UrlImportValidator;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;

/**
 * The sites a link may not be imported from, named in Project settings:
 * nothing is fetched from them — the link itself or a redirect onto one —
 * and the author is told the site is not supported, as any other we cannot
 * take: no error is reported for it.
 */
function blockImportsFrom(string ...$domains): void
{
    ProjectSettings::factory()->create(['blocked_import_domains' => $domains]);
    app(ProjectSettingsManager::class)->flush();
}

it('keeps the domains an administrator typed as a list of plain domains, naming what is not one', function () {
    expect(BlockedImportDomains::parse("Example.com\nhttps://www.Pics.example.net/a/b?c=1, cdn.example.org/path\n\nexample.com\nnot-a-domain\ncom\nbad_host.com"))
        ->toBe([
            'domains' => ['example.com', 'pics.example.net', 'cdn.example.org'],
            'invalid' => ['not-a-domain', 'com', 'bad_host.com'],
        ]);
});

it('blocks a domain and every host under it, never a name that only ends the same', function (string $host, bool $blocked) {
    blockImportsFrom('example.com');

    expect(app(BlockedImportDomains::class)->blocks($host))->toBe($blocked);
})->with([
    'the domain' => ['example.com', true],
    'a host under it' => ['cdn.example.com', true],
    'in capitals, with the root dot' => ['WWW.EXAMPLE.COM.', true],
    'a name that only ends the same' => ['notexample.com', false],
    'another site' => ['example.org', false],
]);

it('refuses a link to a blocked site before its name is even looked up', function () {
    blockImportsFrom('blocked.example');
    $resolver = new class implements HostResolver
    {
        /** @var list<string> */
        public array $looked = [];

        public function resolve(string $host): array
        {
            $this->looked[] = $host;

            return ['93.184.216.34'];
        }
    };
    app()->instance(HostResolver::class, $resolver);

    expect(fn () => app(UrlImportValidator::class)->validate('https://media.blocked.example/a.jpg'))
        ->toThrow(ImportDomainBlockedException::class)
        ->and($resolver->looked)->toBe([]);

    // Any other site is checked as before.
    app(UrlImportValidator::class)->validate('https://example.com/a.jpg');

    expect($resolver->looked)->toBe(['example.com']);
});

it('follows no redirect onto a blocked site', function () {
    bindFakeHostResolver(['blocked.example' => ['93.184.216.36']]);
    blockImportsFrom('blocked.example');
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => 'https://blocked.example/a.jpg'], ''),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(StoreImportedImageAction::class)->download('https://example.com/a.jpg'))
        ->toThrow(ImportDomainBlockedException::class)
        ->and(collect($transport->calls)->pluck('host')->all())->toBe(['example.com']);
});

it('tells the author a pasted link to a blocked site is not supported, fetching and reporting nothing', function () {
    bindFakeHostResolver();
    blockImportsFrom('example.com');
    Exceptions::fake();
    $transport = new ScriptedImportHttpTransport([]);
    app()->instance(ImportHttpTransport::class, $transport);

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->call('previewLink', 'https://www.example.com/photo.jpg')
        ->assertHasErrors(['importedImageUrl'])
        ->assertSee(__('import.errors.domain_blocked'))
        ->set('title', 'A title')
        ->call('submit')
        ->assertSet('submitError', __('import.errors.domain_blocked'));

    expect($transport->calls)->toBe([]);
    Exceptions::assertNothingReported();
});

it('takes a link to any other site as before', function () {
    bindFakeHostResolver();
    blockImportsFrom('blocked.example');

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->call('previewLink', 'https://example.com/photo.jpg')
        ->assertHasNoErrors('importedImageUrl')
        ->assertSet('linkPreview.image', 'https://example.com/photo.jpg');
});

describe('in Project settings', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->admin()->create());
        ProjectSettings::factory()->create();
    });

    it('saves the blocked sites as plain domains, one a line', function () {
        Livewire::test(ProjectSettingsPage::class, ['tab' => 'features'])
            ->assertSeeHtml('data-testid="settings-blocked-import-domains"')
            ->set('data.blocked_import_domains', "https://www.Example.com/post/1\ncdn.example.net, example.com")
            ->dispatch('save-project-settings')
            ->assertHasNoErrors()
            ->assertSet('data.blocked_import_domains', "example.com\ncdn.example.net");

        expect(ProjectSettings::query()->sole()->blocked_import_domains)->toBe(['example.com', 'cdn.example.net'])
            ->and(app(ProjectSettingsManager::class)->current()->blockedImportDomains())->toBe(['example.com', 'cdn.example.net']);
    });

    it('names back what is not a domain, and saves nothing', function () {
        Livewire::test(ProjectSettingsPage::class, ['tab' => 'features'])
            ->set('data.blocked_import_domains', "example.com\nnot a domain")
            ->dispatch('save-project-settings')
            ->assertHasErrors(['data.blocked_import_domains'])
            ->assertSee('Not a domain: “not”, “a” and “domain”. Enter one a line, as example.com.');

        expect(ProjectSettings::query()->sole()->blocked_import_domains)->toBeNull();
    });

    it('never writes back a list it did not change, so one saved since is kept', function () {
        $page = Livewire::test(ProjectSettingsPage::class);
        ProjectSettings::query()->sole()->update(['blocked_import_domains' => ['example.com']]);

        $page->set('data.site_name', 'Pawrank')->dispatch('save-project-settings')->assertHasNoErrors();

        expect(ProjectSettings::query()->sole()->blocked_import_domains)->toBe(['example.com']);
    });
});
