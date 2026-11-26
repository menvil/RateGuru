<?php

use App\Actions\Translations\UpdateProjectTranslationAction;
use App\Filament\Pages\LanguagesPage;
use App\Filament\Pages\TranslationCenterPage;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\RatingGroups\Pages\ManageRatingGroups;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use App\Queries\Translations\OutdatedProjectTranslationsQuery;
use App\Support\Translations\ProjectContentSection;
use Livewire\Livewire;

/*
 * A translation made from English since changed needs an update: Languages and the content screens say how many,
 * beside what is missing, with the way to them in Translation Center's Needs update — not Translation Center alone.
 */

beforeEach(function () {
    [$this->target, $this->other] = twoTranslatedLocales();
    ProjectSettings::factory()->create(['enabled_locales' => ['en', $this->target]]);
    $this->actingAs(User::factory()->admin()->create());
});

it('counts the translations that need an update in each language, over a section or what is given', function () {
    outdatedCategory($this->target, 'Willkommen', 'Welcome', 'Hello');
    outdatedCategory($this->target, 'Katzen', 'Cats', 'Kittens');
    outdatedCategory($this->other, 'Hunde', 'Dogs', 'Puppies');

    expect(app(OutdatedProjectTranslationsQuery::class)->countsIn([ProjectContentSection::Categories], [$this->target, $this->other]))
        ->toBe([$this->target => 2, $this->other => 1])
        ->and(app(OutdatedProjectTranslationsQuery::class)->countsIn([ProjectContentSection::Tags], [$this->target]))->toBe([$this->target => 0]);
});

it('says on a content screen how many of its translations need an update, as the way to them, and nothing while none does', function () {
    Livewire::test(ManageTags::class)->assertDontSee('data-testid="translations-outdated"', false);

    outdatedCategory($this->target, 'Willkommen', 'Welcome', 'Hello');

    Livewire::test(ManageCategories::class)
        ->assertSee('Translations to update')
        ->assertSeeHtml('href="'.e(TranslationCenterPage::getUrl(['locale' => $this->target, 'section' => 'categories', 'mode' => 'outdated'])).'"');

    // Only the languages the site offers count there.
    outdatedCategory($this->other, 'Hunde', 'Dogs', 'Puppies');

    expect(Livewire::test(ManageCategories::class)->viewData('outdated')['count'])->toBe(1);
});

it('says on Languages how many translations of each language need an update, with the way to them', function () {
    outdatedCategory($this->target, 'Willkommen', 'Welcome', 'Hello');
    $page = Livewire::test(LanguagesPage::class);

    expect(collect($page->viewData('rows'))->firstWhere('code', $this->target)['outdated'])->toBe(1)
        ->and(collect($page->viewData('rows'))->firstWhere('code', $this->other)['outdated'])->toBe(0);

    $page->assertSeeHtml('href="'.e(TranslationCenterPage::getUrl(['locale' => $this->target, 'mode' => 'outdated'])).'"')
        ->assertSee('1 to update');
});

it('opens the Rating groups figure on the section whose translations need an update, one section at a time', function () {
    $group = RatingGroup::factory()->create(['is_active' => true]);
    $option = RatingOption::factory()->create(['rating_group_id' => $group->id, 'label' => 'Cute', 'is_active' => true]);
    app(UpdateProjectTranslationAction::class)->handle(User::factory()->admin()->create(), "rating_options:{$option->id}:label", $this->target, 'Translated');
    $option->update(['label' => 'Adorable']);

    expect(Livewire::test(ManageRatingGroups::class)->viewData('outdated'))->toBe([
        'count' => 1,
        'url' => TranslationCenterPage::getUrl(['locale' => $this->target, 'section' => 'rating_options', 'mode' => 'outdated']),
    ]);
});
