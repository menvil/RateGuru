<?php

use App\Enums\TrafficActor;
use App\Enums\TrafficConnector;
use App\Enums\TrafficConnectorFailure;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\ProjectSettings;
use App\Models\TrafficSource;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * People are told the site's own name, from Project settings: never the name
 * of the engine it runs on.
 */

beforeEach(function () {
    ProjectSettings::factory()->create(['site_name' => 'Acme Club']);
    app(ProjectSettingsManager::class)->flush();
    $this->actingAs(User::factory()->admin()->create());
    $this->source = TrafficSource::factory()->create(['name' => 'PopAds main', 'connector' => TrafficConnector::PopAds]);
});

it('never names the engine on a tab of Traffic sources', function (string $tab) {
    Livewire::withQueryParams(['tab' => $tab, 'period' => '30d'])->test(TrafficSourcesPage::class)->call('load')
        ->assertOk()
        ->assertDontSee('RateGuru');
})->with(array_keys(TrafficSourcesPage::TABS));

it('never names the engine on a tab of Analytics', function (string $tab) {
    Livewire::withQueryParams(['tab' => $tab, 'period' => '30d'])->test(GrowthAnalyticsPage::class)
        ->assertOk()
        ->assertDontSee('RateGuru');
})->with(array_keys(GrowthAnalyticsPage::TABS));

it('says who counted the figures by the site’s name', function () {
    Livewire::withQueryParams(['tab' => 'overview', 'period' => '30d'])->test(GrowthAnalyticsPage::class)
        ->assertSee('Counted by Acme Club');
});

it('names the site in what a network connection and its history say', function () {
    expect(TrafficConnectorFailure::Timeout->message())->toContain('Acme Club')->not->toContain('RateGuru')
        ->and(TrafficActor::System->label())->toBe('Acme Club');
});

it('names the site in what a connector promises', function () {
    Livewire::withQueryParams(['tab' => 'sources'])->test(TrafficSourcesPage::class)->call('load')
        ->call('openEditor', (string) $this->source->id)
        ->assertSee('Acme Club only reads statistics')
        ->assertDontSee(':site');
});

it('sends mail under the site’s name from the same address, and leaves a sender a message names itself', function () {
    config(['mail.default' => 'array', 'mail.from.address' => 'hello@example.test', 'mail.from.name' => 'Engine name']);
    Mail::purge('array');

    Mail::raw('Hello', fn ($message) => $message->to('member@example.test')->subject('Default sender'));
    Mail::raw('Hello', fn ($message) => $message->from('team@example.test', 'The team')->to('member@example.test')->subject('Own sender'));

    $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    $sender = fn (int $index) => $sent[$index]->getOriginalMessage()->getFrom()[0];

    expect([$sender(0)->getAddress(), $sender(0)->getName()])->toBe(['hello@example.test', 'Acme Club'])
        ->and([$sender(1)->getAddress(), $sender(1)->getName()])->toBe(['team@example.test', 'The team']);
});
