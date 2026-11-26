<?php

use App\Actions\Translations\UpdateProjectTranslationAction;
use App\Filament\Pages\TranslationCenterPage;
use App\Filament\Resources\Games\Pages\GameVersions;
use App\Models\GameFlowStep;
use App\Models\GameResultProfile;
use App\Models\GameRound;
use App\Models\GameRoundOption;
use App\Models\GameVersion;
use App\Models\ProjectSettings;
use App\Queries\Games\VersionTranslationCoverageQuery;
use App\Support\Games\Drafts\ContentSetReport;
use App\Support\Games\Publishing\VersionSnapshotBuilder;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationUnit;
use Livewire\Livewire;

/*
 * Before a version freezes a game's texts, Versions says which of them have no translation, or one made from
 * English since changed, in each language the site offers besides English — a warning with the way to
 * Translation Center narrowed to the game, never a hold on publishing.
 */

beforeEach(function () {
    [$this->first, $this->second] = twoTranslatedLocales();
    ProjectSettings::factory()->create(['enabled_locales' => ['en', $this->first, $this->second]]);
    $this->draft = publishableGameDraft(1);
    $this->actingAs($this->draft['admin']);
});

/**
 * The texts a version of the draft would freeze that need a translation, as Translation Center lists them.
 *
 * @return list<ProjectTranslationUnit>
 */
function gameVersionUnits(array $draft): array
{
    return array_values(array_filter(
        app(ProjectTranslationCatalog::class)->unitsOfVersion($draft['game']->fresh(), $draft['set']->fresh(), $draft['flow']->fresh()),
        fn (ProjectTranslationUnit $unit): bool => $unit->requiresTranslation(),
    ));
}

/** Every text a version of the draft would freeze translated into $locale, saved as Translation Center saves it. */
function translateGameVersion(array $draft, string $locale): void
{
    foreach (gameVersionUnits($draft) as $unit) {
        app(UpdateProjectTranslationAction::class)->handle($draft['admin'], $unit->id, $locale, $unit->reference);
    }
}

it('warns per offered language of the texts the version would freeze that are missing or need an update', function () {
    translateGameVersion($this->draft, $this->first);
    $count = count(gameVersionUnits($this->draft));
    // The English of one question changes after it was translated.
    GameRound::query()->where('content_set_id', $this->draft['set']->id)->firstOrFail()->update(['question' => 'Which one, honestly?']);
    $label = fn (string $locale): string => app(LocaleManager::class)->label($locale);

    expect(app(VersionTranslationCoverageQuery::class)->gaps($this->draft['game'], $this->draft['set'], $this->draft['flow']))->toBe([
        ['locale' => $this->first, 'language' => $label($this->first), 'missing' => 0, 'outdated' => 1],
        ['locale' => $this->second, 'language' => $label($this->second), 'missing' => $count, 'outdated' => 0],
    ]);

    $page = Livewire::test(GameVersions::class, ['record' => $this->draft['game']->id])
        ->assertSee('Not every text this version freezes is translated.')
        ->assertSee($label($this->first).':')
        ->assertSee('1 needs an update')
        ->assertSee($count.' missing');

    // Each figure opens Translation Center on this game's texts, in that language, missing or needing an update.
    expect(collect($page->viewData('translationGaps'))->map(fn (array $gap): array => [$gap['missingUrl'], $gap['outdatedUrl']])->all())->toBe([
        [null, TranslationCenterPage::getUrl(['locale' => $this->first, 'game' => $this->draft['game']->id, 'mode' => 'outdated'])],
        [TranslationCenterPage::getUrl(['locale' => $this->second, 'game' => $this->draft['game']->id, 'mode' => 'missing']), null],
    ])
        ->and($page->viewData('canTranslate'))->toBeTrue();

    // The publish dialog names the languages again, and publishing goes ahead.
    $page->call('askPublish')
        ->assertSee('Not translated in full: '.$label($this->first).', '.$label($this->second).'.')
        ->call('publish');

    expect(GameVersion::query()->where('game_id', $this->draft['game']->id)->count())->toBe(1);
});

it('says nothing once every text the version freezes is translated and current, whatever another game lacks', function () {
    translateGameVersion($this->draft, $this->first);
    translateGameVersion($this->draft, $this->second);
    publishableGameDraft(1);

    expect(app(VersionTranslationCoverageQuery::class)->gaps($this->draft['game'], $this->draft['set'], $this->draft['flow']))->toBe([]);

    Livewire::test(GameVersions::class, ['record' => $this->draft['game']->id])
        ->assertDontSee('data-testid="game-versions-translations"', false)
        ->call('askPublish')
        ->assertDontSee('data-testid="game-publish-translations"', false);
});

it('counts no language while the site offers English alone', function () {
    ProjectSettings::query()->update(['enabled_locales' => ['en']]);
    app(ProjectSettingsManager::class)->flush();

    expect(app(VersionTranslationCoverageQuery::class)->gaps($this->draft['game'], $this->draft['set'], $this->draft['flow']))->toBe([]);
});

it('counts exactly the texts a version freezes, no more and no fewer', function () {
    // Every text the draft would freeze given English of its own, so a text left out on either side shows.
    GameRound::query()->where('content_set_id', $this->draft['set']->id)->get()->each(fn (GameRound $round) => $round->update(['question' => "Question {$round->id}", 'description' => "Description {$round->id}", 'reveal_message' => "Reveal {$round->id}"]));
    GameRoundOption::query()->whereIn('round_id', GameRound::query()->where('content_set_id', $this->draft['set']->id)->select('id'))->get()->each(fn (GameRoundOption $option) => $option->update(['label' => "Label {$option->id}"]));
    GameResultProfile::query()->where('content_set_id', $this->draft['set']->id)->get()->each(fn (GameResultProfile $profile) => $profile->update(['name' => "Result {$profile->id}", 'description' => "About {$profile->id}", 'share_text' => "Share {$profile->id} {profile} {game}"]));
    GameFlowStep::query()->where('flow_id', $this->draft['flow']->id)->get()->each(fn (GameFlowStep $step) => $step->update(collect($step->type->texts())->mapWithKeys(fn (array $text, string $field): array => [$field => "Step {$step->id} {$field}"])->all()));
    $this->draft['game']->update(['title' => 'A title of its own', 'description' => 'A description of its own']);

    $snapshot = VersionSnapshotBuilder::buildForPreview($this->draft['game']->fresh(), ContentSetReport::of($this->draft['set']->fresh()), $this->draft['flow']->fresh()->load('steps'))->toArray();
    $frozen = [];
    $collect = function (array $node) use (&$collect, &$frozen): void {
        if (isset($node['en']) && is_string($node['en']) && trim($node['en']) !== '') {
            $frozen[] = $node['en'];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $collect($child);
            }
        }
    };
    $collect($snapshot);

    $listed = array_map(fn (ProjectTranslationUnit $unit): string => $unit->reference, gameVersionUnits($this->draft));
    sort($frozen);
    sort($listed);

    // Two game texts, three of the round, two options, every result's three and the flow's: a real list.
    expect(count($frozen))->toBeGreaterThan(10)
        ->and($listed)->toBe($frozen);
});
