<?php

use App\Enums\GameFlowGoal;
use App\Enums\GameFlowStepType;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\EditGameFlow;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
    $this->actingAs($this->draft['admin']);
});

it('shows the goal of a flow that follows its steps, and the step that reaches it', function () {
    $this->get(GameResource::getUrl('flow', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSee('Goal of this flow')
        ->assertSee('Follow the steps · Account confirmed')
        ->assertSee('Counted on step 3 · Full Result.')
        ->assertSee('One goal per flow. A published version keeps its goal; a change here counts from the next version you publish.')
        ->assertSee('data-testid="game-flow-goal-chip"', false);
});

it('saves the goal chosen, and offers the step a goal out of reach needs', function () {
    $page = Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->set('goal', 'email')
        ->assertSee('Nothing in this flow can reach this goal. Add an Email Capture step, or choose another goal.')
        ->assertSee('The goal “'.GameFlowGoal::Email->label().'” needs an Email Capture step.')
        ->call('addGoalStep');

    expect(collect($page->get('steps'))->pluck('type')->all())->toContain(GameFlowStepType::EmailCapture->value);

    $page->call('save')->assertHasNoErrors();

    expect($this->draft['flow']->fresh()->goal?->value)->toBe('email');

    $page->set('goal', '')->call('save');

    expect($this->draft['flow']->fresh()->goal)->toBeNull();
});

it('links a post to a Go to Post step by its address, and finds one by words of its title', function () {
    $post = Post::factory()->published()->create(['title' => 'A very fluffy cat']);
    $host = parse_url((string) config('app.url'), PHP_URL_HOST);

    $page = Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->call('insertStep', 'go_to_post')
        ->set('steps.3.settings.post_target', 'chosen')
        ->set('postLink', 'https://elsewhere.example/posts/'.$post->id)
        ->call('linkPost')
        ->assertSet('postLinkError', 'No post with that link on '.$host.'. Only posts on this site can be linked.')
        ->set('postLink', 'fluffy')
        ->call('linkPost')
        ->assertSee('A very fluffy cat')
        ->call('choosePost', $post->id)
        ->assertSet('steps.3.settings.post_id', $post->id)
        ->assertSee('data-testid="game-flow-linked-post"', false)
        ->assertDispatched('rg-admin-toast');

    $page->call('unlinkPost')->assertSet('steps.3.settings.post_id', null)
        ->set('postLink', 'https://'.$host.'/posts/'.$post->id)
        ->call('linkPost')
        ->assertSet('steps.3.settings.post_id', $post->id)
        ->set('postLink', 'no such words here')
        ->call('linkPost')
        ->assertSet('postLinkError', 'No post title or author has these words.');
});

it('asks for a rating group on the post, and stops asking', function () {
    RatingOption::factory()->for(RatingGroup::factory()->create(['key' => 'cuteness']), 'group')->create();

    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->call('insertStep', 'go_to_post')
        ->call('toggleAsk', 'cuteness')
        ->assertSet('steps.3.settings.ask_groups', 'cuteness')
        ->call('toggleAsk', 'cuteness')
        ->assertSet('steps.3.settings.ask_groups', '');
});

it('blocks sign-up and sharing while Full Result sends visitors on', function () {
    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->call('selectStep', 2)
        ->set('steps.2.settings.onward', 'feed')
        ->assertSee('Sign-up and share buttons are not shown while Full Result sends visitors on.')
        ->assertSee('Which feed')
        ->assertDontSee('Sign-up for anonymous visitors');
});
