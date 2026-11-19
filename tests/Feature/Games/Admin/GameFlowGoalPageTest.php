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
        ->assertSeeInOrder(['Follow the steps', 'Now Account confirmed'])
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
        ->assertSee('Three votes in the feed within 30 minutes count as Voted in the feed.')
        ->assertSee('Which feed')
        ->assertDontSee('Sign-up for anonymous visitors');
});

it('prices each step on its card and in its panel, mobile and desktop, from the worked-out chances', function () {
    $game = $this->draft['game'];
    finishedChanceRun([
        'g'.$game->id.'|d:mobile' => ['opened' => ['chance' => 0.1], 'started' => ['chance' => 0.2], 'completed' => ['chance' => 0.4]],
        'g'.$game->id.'|d:desktop' => ['opened' => ['chance' => 0.1], 'started' => ['chance' => 0.3], 'completed' => ['chance' => 0.6]],
    ]);

    $page = Livewire::test(EditGameFlow::class, ['record' => $game->id])->call('selectStep', 1);
    $cards = collect($page->viewData('cards'))->keyBy('label');

    // An account is $0.50 before bonuses: a step is worth its chance of one, a step with several rows its best.
    expect($cards['Landing']['worth']['text'])->toBe('$0.050')
        ->and($cards['Game']['worth']['text'])->toBe('up to $0.20 / $0.30')
        ->and($cards['Game']['worth']['tip'])->toContain('Round 1 answered · game finished $0.20 / $0.30');

    $page->assertSee('What this step is worth')
        ->assertSeeInOrder(['When reached', 'Game started', '$0.10', '$0.15', 'game finished', '$0.20', '$0.30'])
        ->assertSee('All prices');
});

it('prices only what reaches an account while nothing is worked out', function () {
    $page = Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])->call('selectStep', 0);
    $cards = collect($page->viewData('cards'))->keyBy('label');

    // The account is worth its price whatever the chances; the steps before it are worth nothing yet.
    expect($cards['Landing']['worth'])->toBeNull()
        ->and($cards['Game']['worth'])->toBeNull()
        ->and($cards['Full Result']['worth']['text'])->toBe('up to $0.50')
        ->and($page->html())->toContain('Not worked out yet');
});

it('chooses a goal from the menu, and goes back to the steps', function () {
    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->assertSeeHtml('data-testid="game-flow-goal-option-email"')
        ->assertSee('Needs an Email Capture step')
        ->set('goal', 'email')
        ->assertSee('Nothing in this flow can reach this goal.')
        ->set('goal', '')
        ->assertSet('goal', '')
        ->assertDontSee('Nothing in this flow can reach this goal.');
});

it('looks a post up by its title as it is typed, and links the one picked', function () {
    $post = gamePost(900, 100);
    $post->forceFill(['title' => 'A very particular puppy'])->save();

    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->call('insertStep', 'go_to_post')
        ->set('steps.3.settings.post_target', 'chosen')
        ->set('postLink', 'particular pup')
        ->assertSet('postMatches.0.id', $post->id)
        ->assertSee('A very particular puppy')
        ->call('choosePost', $post->id)
        ->assertSet('steps.3.settings.post_id', $post->id)
        ->assertSet('postMatches', [])
        ->assertSee('#'.$post->id.' · by');
});

it('asks for rating groups on a Full Result saved before it could send to a post', function () {
    RatingGroup::factory()->create(['key' => 'cuteness']);
    $page = Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->call('selectStep', 2)
        ->set('steps.2.settings', ['show_score' => true, 'show_profile' => true, 'share_enabled' => true, 'signup' => 'inline', 'onward' => 'post']);

    $page->call('toggleAsk', 'cuteness')->assertSet('steps.2.settings.ask_groups', 'cuteness');
});
