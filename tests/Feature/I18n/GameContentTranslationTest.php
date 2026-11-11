<?php

use App\Actions\Games\PublishGameVersionAction;
use App\Actions\Games\SaveGameFlowAction;
use App\Actions\Translations\UpdateProjectTranslationAction;
use App\Enums\GameFlowStepType;
use App\Enums\GameStatus;
use App\Exceptions\Translations\CannotSaveTranslationException;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\GameVersions;
use App\Filament\Support\TranslationSourceEditor;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\GameRound;
use App\Models\GameVersion;
use App\Models\ProjectSettings;
use App\Support\Games\ContentSetDraftRules;
use App\Support\Games\GameGeneralRules;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationCompleteness;
use App\Support\Translations\ProjectTranslationUnit;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * A game's content is the project's own, translated in Translation Center like
 * the rest of it: the game's title and description, the rounds, options and
 * results of its content sets, and the texts of its flow steps — from the
 * first draft, so a game is translated before it is published. Players read a
 * game from its published version, which keeps the translations it was
 * published with.
 */
function gameTranslationUnits(): array
{
    return collect(app(ProjectTranslationCatalog::class)->units())
        ->filter(fn (ProjectTranslationUnit $unit): bool => str_starts_with($unit->section->value, 'game'))
        ->keyBy('id')
        ->all();
}

afterEach(function () {
    Carbon::setTestNow();
});

it('lists a game, the rounds, options and results of its sets, and the texts its flow steps use', function () {
    ProjectSettings::factory()->create();
    ['game' => $game, 'set' => $set, 'flow' => $flow] = publishableGameDraft(rounds: 2);
    $round = $set->rounds()->orderBy('position')->get()[1];
    $option = $round->options()->where('position', 1)->first();
    $profile = $set->profiles()->orderBy('position')->first();
    $landing = $flow->steps()->where('type', GameFlowStepType::Landing->value)->first();

    $units = gameTranslationUnits();

    expect(array_keys(array_filter($units, fn (ProjectTranslationUnit $unit): bool => $unit->section === ProjectContentSection::Games)))
        ->toBe(["games:{$game->id}:title", "games:{$game->id}:description"])
        ->and(array_keys(array_filter($units, fn (ProjectTranslationUnit $unit): bool => $unit->recordId === $round->id && $unit->section === ProjectContentSection::GameRounds)))
        ->toBe(["game_rounds:{$round->id}:question", "game_rounds:{$round->id}:description", "game_rounds:{$round->id}:reveal_message"])
        // A step lists the texts its type uses, and none of the others.
        ->and(array_values(array_map(fn (ProjectTranslationUnit $unit): string => $unit->field, array_filter($units, fn (ProjectTranslationUnit $unit): bool => $unit->recordId === $landing->id && $unit->section === ProjectContentSection::GameFlowSteps))))
        ->toBe(array_keys(GameFlowStepType::Landing->texts()));

    $question = $units["game_rounds:{$round->id}:question"];
    $label = $units["game_round_options:{$option->id}:label"];
    $share = $units["game_result_profiles:{$profile->id}:share_text"];
    $title = $units["game_flow_steps:{$landing->id}:title"];

    expect([$question->parentId, $question->key, $question->label, $question->maxLength, $question->multiline])
        ->toBe([$game->id, "{$game->slug}.set-{$set->id}.round-2", "{$game->title} → {$set->name} → Round 2", 120, true])
        ->and([$label->key, $label->label, $label->maxLength, $label->multiline])
        ->toBe(["{$game->slug}.set-{$set->id}.round-2.b", "{$game->title} → {$set->name} → Round 2 → B", 60, false])
        ->and([$share->key, $share->label])->toBe(["{$game->slug}.set-{$set->id}.{$profile->key}", "{$game->title} → {$set->name} → {$profile->name}"])
        ->and([$title->key, $title->label, $title->maxLength])->toBe(["{$game->slug}.flow-{$flow->id}.landing", "{$game->title} → {$flow->name} → Landing", 160])
        ->and($title->usage)->toContain('hero title of the game’s Landing screen')
        ->and($question->usage)->toContain('once a version of the game is published after it');
});

it('opens each piece of a game’s content where its editor shows it', function () {
    ProjectSettings::factory()->create();
    ['game' => $game, 'set' => $set, 'flow' => $flow] = publishableGameDraft(rounds: 2);
    $round = $set->rounds()->where('position', 1)->first();
    $option = $round->options()->where('position', 0)->first();
    $profile = $set->profiles()->first();
    $step = $flow->steps()->where('position', 2)->first();
    $units = gameTranslationUnits();
    $url = fn (string $id): string => TranslationSourceEditor::url($units[$id]);

    expect($url("games:{$game->id}:title"))->toBe(GameResource::getUrl('edit', ['record' => $game->id]))
        ->and($url("game_rounds:{$round->id}:question"))->toBe(GameResource::getUrl('content', ['record' => $game->id, 'set' => $set->id, 'round' => 1]))
        ->and($url("game_round_options:{$option->id}:label"))->toBe(GameResource::getUrl('content', ['record' => $game->id, 'set' => $set->id, 'round' => 1]))
        ->and($url("game_result_profiles:{$profile->id}:name"))->toBe(GameResource::getUrl('content', ['record' => $game->id, 'set' => $set->id, 'view' => 'results']))
        ->and($url(collect($units)->first(fn (ProjectTranslationUnit $unit): bool => $unit->section === ProjectContentSection::GameFlowSteps && $unit->recordId === $step->id)->id))
        ->toBe(GameResource::getUrl('flow', ['record' => $game->id, 'flow' => $flow->id, 'step' => 2]));
});

it('leaves out archived games, archived sets and archived flows', function () {
    ProjectSettings::factory()->create();
    ['game' => $game, 'set' => $set, 'flow' => $flow] = publishableGameDraft(rounds: 1);
    $archivedSet = GameContentSet::factory()->create(['game_id' => $game->id, 'archived_at' => now()]);
    $archivedRound = GameRound::factory()->create(['content_set_id' => $archivedSet->id]);
    $archivedFlow = GameFlow::factory()->create(['game_id' => $game->id, 'archived_at' => now()]);
    $archivedStep = GameFlowStep::factory()->type(GameFlowStepType::Landing)->create(['flow_id' => $archivedFlow->id]);
    ['game' => $archivedGame, 'set' => $setOfArchived] = publishableGameDraft(rounds: 1);
    $archivedGame->forceFill(['status' => GameStatus::Archived])->save();
    $roundOfArchived = $setOfArchived->rounds()->first();

    $ids = array_keys(gameTranslationUnits());

    expect($ids)->toContain("games:{$game->id}:title")
        ->toContain('game_rounds:'.$set->rounds()->value('id').':question')
        ->not->toContain("game_rounds:{$archivedRound->id}:question")
        ->not->toContain("game_flow_steps:{$archivedStep->id}:title")
        ->not->toContain("games:{$archivedGame->id}:title")
        ->not->toContain("game_rounds:{$roundOfArchived->id}:question")
        ->and(app(ProjectTranslationCatalog::class)->find("game_rounds:{$archivedRound->id}:question"))->toBeNull()
        ->and(app(ProjectTranslationCatalog::class)->find("game_rounds:{$roundOfArchived->id}:question"))->toBeNull();
});

it('finds one piece of a game’s content by its id, the same as the list has it', function () {
    ProjectSettings::factory()->create();
    ['set' => $set, 'flow' => $flow] = publishableGameDraft(rounds: 1);
    $option = $set->rounds()->first()->options()->first();
    $step = $flow->steps()->where('type', GameFlowStepType::Landing->value)->first();
    $units = gameTranslationUnits();
    $catalog = app(ProjectTranslationCatalog::class);

    expect($catalog->find("game_round_options:{$option->id}:label"))->toEqual($units["game_round_options:{$option->id}:label"])
        ->and($catalog->findMany(["game_flow_steps:{$step->id}:title"]))->toEqual(["game_flow_steps:{$step->id}:title" => $units["game_flow_steps:{$step->id}:title"]])
        // The Game step has no texts at all.
        ->and($catalog->find('game_flow_steps:'.$flow->steps()->where('type', GameFlowStepType::Game->value)->value('id').':title'))->toBeNull();
});

it('counts a game’s content in a language’s completeness', function () {
    [$target] = twoTranslatedLocales();
    ProjectSettings::factory()->create();
    ['game' => $game] = publishableGameDraft(rounds: 1);

    $before = app(ProjectTranslationCompleteness::class)->report($target);
    $game->forceFill(['title_translations' => [$target => 'Übersetzt']])->save();
    $after = app(ProjectTranslationCompleteness::class)->report($target);

    expect(collect($before->missing)->pluck('id'))->toContain("games:{$game->id}:title")
        ->and(collect($after->missing)->pluck('id'))->not->toContain("games:{$game->id}:title")
        ->and($after->translated)->toBe($before->translated + 1)
        ->and(array_keys($before->missingBySection()))->toContain('games', 'game_rounds', 'game_result_profiles', 'game_flow_steps');
});

it('saves a translation on the content it translates, which the next version takes and an earlier one keeps without', function () {
    [$target] = twoTranslatedLocales();
    ProjectSettings::factory()->create();
    $draft = publishableGameDraft(rounds: 1);
    $round = $draft['set']->rounds()->first();
    $first = app(PublishGameVersionAction::class)->handle($draft['admin'], $draft['game'], $draft['set']->id, $draft['flow']->id);

    app(UpdateProjectTranslationAction::class)->handle($draft['admin'], "game_rounds:{$round->id}:question", $target, 'Welches würdest du lieber sehen?');
    $second = app(PublishGameVersionAction::class)->handle($draft['admin'], $draft['game']->fresh(), $draft['set']->id, $draft['flow']->id);

    expect($round->fresh()->question_translations)->toBe([$target => 'Welches würdest du lieber sehen?'])
        ->and(GameVersion::query()->find($first->id)->read()->round(0)->question->in($target))->toBe($round->question)
        ->and(GameVersion::query()->find($second->id)->read()->round(0)->question->in($target))->toBe('Welches würdest du lieber sehen?');
});

it('says a set changed after its version when only a translation of it did', function () {
    [$target] = twoTranslatedLocales();
    ProjectSettings::factory()->create();
    Carbon::setTestNow('2026-11-09 10:00:00');
    $draft = publishableGameDraft(rounds: 1);
    $profile = $draft['set']->profiles()->first();
    app(PublishGameVersionAction::class)->handle($draft['admin'], $draft['game'], $draft['set']->id, $draft['flow']->id);
    $this->actingAs($draft['admin']);

    Livewire::test(GameVersions::class, ['record' => $draft['game']->id])
        ->assertSee('already uses this set and flow, and neither changed since')
        ->assertDontSee('or a translation of it, changed after V1');

    Carbon::setTestNow('2026-11-09 11:00:00');
    app(UpdateProjectTranslationAction::class)->handle($draft['admin'], "game_result_profiles:{$profile->id}:name", $target, 'Übersetzt');

    Livewire::test(GameVersions::class, ['record' => $draft['game']->id])
        ->assertSee('or a translation of it, changed after V1');
});

it('holds a translation of a game’s content to the limits its English editor enforces', function () {
    $fields = ProjectTranslationCatalog::FIELDS;

    expect($fields['games'])->toBe(['title' => [GameGeneralRules::TITLE_MAX, false], 'description' => [GameGeneralRules::DESCRIPTION_MAX, true]])
        ->and($fields['game_rounds'])->toBe([
            'question' => [ContentSetDraftRules::QUESTION_MAX, true],
            'description' => [ContentSetDraftRules::DESCRIPTION_MAX, true],
            'reveal_message' => [ContentSetDraftRules::REVEAL_MAX, true],
        ])
        ->and($fields['game_round_options'])->toBe(['label' => [ContentSetDraftRules::LABEL_MAX, false]])
        ->and($fields['game_result_profiles'])->toBe([
            'name' => [ContentSetDraftRules::PROFILE_NAME_MAX, false],
            'description' => [ContentSetDraftRules::PROFILE_DESCRIPTION_MAX, true],
            'share_text' => [ContentSetDraftRules::SHARE_TEXT_MAX, true],
        ])
        // Every text column a step can have, each as long as its editor allows; the step editor draws these three as text areas.
        ->and(array_map(fn (array $limits): int => $limits[0], $fields['game_flow_steps']))->toBe(SaveGameFlowAction::TEXT_MAX)
        ->and(array_keys(array_filter($fields['game_flow_steps'], fn (array $limits): bool => $limits[1])))->toBe(['body', 'notice', 'consent_label'])
        ->and(array_keys($fields['game_flow_steps']))->toBe(GameFlowStepType::TEXT_COLUMNS);
});

it('keeps a share text’s placeholders in every translation of it', function () {
    [$target] = twoTranslatedLocales();
    ProjectSettings::factory()->create();
    ['admin' => $admin, 'set' => $set] = publishableGameDraft(rounds: 1);
    $profile = $set->profiles()->first();
    $profile->forceFill(['share_text' => 'I am {profile} in {game}!'])->save();

    expect(fn () => app(UpdateProjectTranslationAction::class)->handle($admin, "game_result_profiles:{$profile->id}:share_text", $target, 'Ich bin {profile}!'))
        ->toThrow(CannotSaveTranslationException::class);

    app(UpdateProjectTranslationAction::class)->handle($admin, "game_result_profiles:{$profile->id}:share_text", $target, 'Ich bin {profile} in {game}!');

    expect($profile->fresh()->share_text_translations)->toBe([$target => 'Ich bin {profile} in {game}!']);
});
