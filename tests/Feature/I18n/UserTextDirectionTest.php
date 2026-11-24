<?php

use App\Actions\Posts\DeletePostAction;
use App\Enums\GameFlowStepType;
use App\Livewire\Comments\CommentsSection;
use App\Livewire\Feed\UploadPostForm;
use App\Livewire\Games\PlayGame;
use App\Livewire\Notifications\NotificationBell;
use App\Livewire\Posts\RecentlyDeletedPostsPage;
use App\Mail\ContactMessageMail;
use App\Models\Category;
use App\Models\Comment;
use App\Models\GameFlowStep;
use App\Models\GameResultProfile;
use App\Models\GameRound;
use App\Models\GameRoundOption;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Models\User;
use App\Notifications\FollowedAuthorPostedNotification;
use App\Support\Games\Play\StepText;
use App\Support\Locale\IsolatedTranslation;
use App\Support\Locale\WrittenText;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/*
 * Text people write — a post's title and description, a comment, a name and
 * a bio, what an administrator writes for a game, a category, a rating or a
 * page of the site — reads its own way, wherever the page is read: dir="auto"
 * on the block that holds it, a <bdi> around it where it sits among the
 * page's own words. An English headline on an Arabic page keeps its "?" at its
 * end, an Arabic title on an English page reads right to left. The site's own
 * words — its menus, buttons and headings — keep the page's direction.
 *
 * The pages are served in a language read right to left with English written
 * on them: what is checked is the markup, which is the same in every language.
 */

beforeEach(function () {
    ProjectSettings::factory()->create();
    offerLocales(rightToLeftLocales());
    $this->locale = rightToLeftLocales()[0];
    $this->withHeaders(acceptLanguage($this->locale));
});

/**
 * The element on the page — in its body, not its <title> — that holds this
 * text itself, or null when the page does not show it.
 */
function writtenTextHolder(string $html, string $text): ?DOMElement
{
    $holder = livewireDom($html)->query('//body//*[text()[normalize-space(.) = "'.$text.'"]]')->item(0);

    return $holder instanceof DOMElement ? $holder : null;
}

/**
 * How the element that holds this text marks it: "auto" when the element
 * itself reads its own way (dir="auto"), "bdi" when the text sits in a <bdi>,
 * null when it takes the direction of whatever is around it. Null as well when
 * the page does not show the text at all, so a missing text fails too.
 */
function writtenTextMark(string $html, string $text): ?string
{
    $holder = writtenTextHolder($html, $text);

    return match (true) {
        $holder === null => null,
        $holder->getAttribute('dir') === 'auto' => 'auto',
        strtolower($holder->tagName) === 'bdi' => 'bdi',
        default => null,
    };
}

/**
 * Whether any element around this text — the one that holds it or one it sits
 * in — reads its own way, or the page does not show the text: for the site's
 * own words, which keep the page's direction.
 */
function readsItsOwnWayOrIsMissing(string $html, string $text): bool
{
    $holder = writtenTextHolder($html, $text);

    for ($node = $holder; $node instanceof DOMElement; $node = $node->parentNode) {
        if ($node->getAttribute('dir') === 'auto' || strtolower($node->tagName) === 'bdi') {
            return true;
        }
    }

    return $holder === null;
}

/** The `dir` of the field this query finds first, or null when it has none and takes the page's direction. */
function fieldDirection(string $html, string $query): ?string
{
    $field = livewireDom($html)->query($query)->item(0);

    expect($field)->toBeInstanceOf(DOMElement::class, "no field [{$query}] on the page");

    return $field->hasAttribute('dir') ? $field->getAttribute('dir') : null;
}

/** Whether the field of this name, the first the page has, reads what is typed into it its own way. */
function fieldReadsItsOwnWay(string $html, string $query): bool
{
    return fieldDirection($html, $query) === 'auto';
}

/** A published post with English written all over it: its title, description, author, comment, category, tag and the site's rating. */
function postWrittenInEnglish(): Post
{
    $author = User::factory()->create(['username' => 'jane_photos', 'display_name' => 'Jane Photos', 'bio' => 'I post a photo every day.', 'profile_website_url' => 'https://jane.example/photos/']);
    $category = Category::factory()->create(['name' => 'Street style!']);
    $post = Post::factory()->published()->for($author)->create([
        'title' => 'Which one is better?',
        'description' => 'Look at both photos, then vote.',
        'category_id' => $category->id,
    ]);
    $post->tags()->attach(Tag::factory()->create(['slug' => 'streetwear']));
    Comment::factory()->for($post)->for(User::factory()->create(['display_name' => 'Kate Commenter']))->create(['body' => 'I like the first one!']);

    $group = RatingGroup::factory()->create(['label' => 'Would you wear it?', 'min_options' => 2]);
    RatingOption::factory()->create(['rating_group_id' => $group->id, 'label' => 'Yes!', 'sort_order' => 1]);
    RatingOption::factory()->create(['rating_group_id' => $group->id, 'label' => 'No way.', 'sort_order' => 2]);

    return $post;
}

it('lets a post\'s title, description, comments and names read their own way on its page, and keeps the page\'s own words in its direction', function () {
    $post = postWrittenInEnglish();

    $html = $this->get(route('posts.show', $post))->assertOk()->getContent();

    expect(writtenTextMark($html, 'Which one is better?'))->toBe('auto')
        ->and(writtenTextMark($html, 'Look at both photos, then vote.'))->toBe('auto')
        ->and(writtenTextMark($html, 'I like the first one!'))->toBe('auto')
        ->and(writtenTextMark($html, 'Jane Photos'))->toBe('auto')
        ->and(writtenTextMark($html, 'Street style!'))->toBe('bdi')
        ->and(writtenTextMark($html, 'Would you wear it?'))->toBe('auto')
        ->and(writtenTextMark($html, 'Yes!'))->toBe('auto')
        ->and(writtenTextMark($html, 'No way.'))->toBe('auto')
        // Already read its own way before: the @name and the tag.
        ->and(writtenTextMark($html, '@jane_photos'))->toBe('bdi')
        ->and(writtenTextMark($html, '#streetwear'))->toBe('auto');

    foreach (['sharing.share', 'ui.nav.home', 'ui.feed.categories_heading', 'ui.nav.about'] as $key) {
        expect(readsItsOwnWayOrIsMissing($html, __($key, [], $this->locale)))->toBeFalse("[{$key}] is the site's own: it keeps the page's direction");
    }
});

it('lets the comment box and a reply read what is typed its own way', function () {
    $post = postWrittenInEnglish();
    $this->actingAs(User::factory()->create(['locale' => $this->locale]));

    $html = $this->get(route('posts.show', $post))->assertOk()->getContent();

    expect(fieldReadsItsOwnWay($html, '//input[@name="body"]'))->toBeTrue()
        ->and(fieldReadsItsOwnWay($html, '//input[@name="search"]'))->toBeTrue()
        ->and(readsItsOwnWayOrIsMissing($html, __('ui.comments.post', [], $this->locale)))->toBeFalse();

    $comment = Comment::query()->sole();
    $page = Livewire::test(CommentsSection::class, ['postId' => $post->id])->call('startReply', $comment->id);

    expect(fieldReadsItsOwnWay($page->html(), '//input[@name="replyBody"]'))->toBeTrue();
});

it('lets a post card\'s title and description, the sidebar\'s categories and tags, and a search read their own way in the feed', function () {
    postWrittenInEnglish();

    $html = $this->get(route('feed'))->assertOk()->getContent();

    expect(writtenTextMark($html, 'Which one is better?'))->toBe('auto')
        ->and(writtenTextMark($html, 'Look at both photos, then vote.'))->toBe('auto')
        ->and(writtenTextMark($html, 'Jane Photos'))->toBe('auto')
        // A category in the sidebar's list stays at its start; a tag reads as a post's tag does.
        ->and(livewireDom($html)->query('//*[@data-testid="app-sidebar"]//bdi[normalize-space(.) = "Street style!"]')->length)->toBeGreaterThan(0)
        ->and(livewireDom($html)->query('//*[@data-testid="app-sidebar"]//a[@dir="auto"][normalize-space(.) = "#streetwear"]')->length)->toBeGreaterThan(0)
        ->and(readsItsOwnWayOrIsMissing($html, __('ui.feed.categories_heading', [], $this->locale)))->toBeFalse();

    $search = $this->get(route('feed', ['search' => 'nothing like this?']))->assertOk()->getContent();

    expect(writtenTextMark($search, 'nothing like this?'))->toBe('bdi')
        ->and(writtenTextMark($search, __('ui.feed.no_results_title', [], $this->locale)))->toBeNull();
});

it('lets a profile\'s name and bio read their own way, and its address read left to right', function () {
    $post = postWrittenInEnglish();

    $html = $this->get(route('profile.show', $post->user->username))->assertOk()->getContent();
    $website = livewireDom($html)->query('//*[@data-testid="profile-website"]')->item(0);

    expect(writtenTextMark($html, 'Jane Photos'))->toBe('auto')
        ->and(writtenTextMark($html, 'I post a photo every day.'))->toBe('auto')
        ->and($website?->getAttribute('dir'))->toBe('ltr');
});

it('lets a name and a bio typed on the profile page read their own way, never an address or a password', function () {
    $this->actingAs(User::factory()->create(['locale' => $this->locale]));

    $html = $this->get(route('profile.edit'))->assertOk()->getContent();

    expect(fieldReadsItsOwnWay($html, '//*[@id="edit-display-name"]'))->toBeTrue()
        ->and(fieldReadsItsOwnWay($html, '//*[@id="edit-bio"]'))->toBeTrue()
        // A web address reads left to right in every language, its "://" never turned round.
        ->and(fieldDirection($html, '//*[@id="edit-website"]'))->toBe('ltr')
        ->and(fieldReadsItsOwnWay($html, '//input[@type="email"]'))->toBeFalse()
        ->and(fieldReadsItsOwnWay($html, '//input[@type="password"]'))->toBeFalse();
});

it('lets the title and description typed into a new post read their own way', function () {
    $this->actingAs(User::factory()->create(['locale' => $this->locale]));

    $html = Livewire::test(UploadPostForm::class)->html();

    expect(fieldReadsItsOwnWay($html, '//*[@id="title"]'))->toBeTrue()
        ->and(fieldReadsItsOwnWay($html, '//*[@id="description"]'))->toBeTrue();
});

it('lets what an administrator writes for a game read its own way on every screen, and keeps the game\'s own words in the page\'s direction', function () {
    // Livewire's test requests do not pass through the language middleware.
    app()->setLocale($this->locale);
    $draft = publishableGameDraft(1);
    $draft['game']->forceFill(['title' => 'Pairs game!'])->save();
    GameFlowStep::query()->where('flow_id', $draft['flow']->id)->where('type', 'landing')->update([
        'title' => 'Which one would you pick?',
        'body' => 'Pick the photo you like more.',
        'button_label' => 'Start playing!',
    ]);
    GameRound::query()->where('content_set_id', $draft['set']->id)->update(['question' => 'Which one would you rather see?', 'description' => 'Go with your gut.']);
    GameRoundOption::query()->where('position', 0)->update(['label' => 'A very long English label for this option']);
    GameResultProfile::query()->where('content_set_id', $draft['set']->id)->update(['name' => 'Taste maker!', 'description' => 'Your taste is close to the crowd.']);
    $draft['game'] = $draft['game']->fresh();
    liveGame($draft);
    $slug = $draft['game']->slug;
    $page = fn () => Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('s', 40))->test(PlayGame::class, ['slug' => $slug]);

    $landing = $page()->call('init')->html();

    expect(writtenTextMark($landing, 'Which one would you pick?'))->toBe('auto')
        ->and(writtenTextMark($landing, 'Pick the photo you like more.'))->toBe('auto')
        ->and(writtenTextMark($landing, 'Start playing!'))->toBe('bdi')
        // The site's name in the game's own sentence.
        ->and(livewireDom($landing)->query('//*[contains(@class, "rg-game__note")]/bdi')->length)->toBe(1)
        ->and(readsItsOwnWayOrIsMissing($landing, __('games.play.skip_to_feed', [], $this->locale)))->toBeFalse();

    $round = $page()->call('init')->call('continue')->html();

    expect(writtenTextMark($round, 'Which one would you rather see?'))->toBe('auto')
        ->and(writtenTextMark($round, 'Go with your gut.'))->toBe('auto')
        // The pill stays at the start of the picture; the words inside read, and are cut, their own way.
        ->and(writtenTextMark($round, 'A very long English label for this option'))->toBe('auto')
        ->and(writtenTextHolder($round, 'A very long English label for this option')?->parentNode?->getAttribute('class'))->toBe('rg-game__label')
        ->and(readsItsOwnWayOrIsMissing($round, __('games.play.pair_of', ['current' => 1, 'total' => 1], $this->locale)))->toBeFalse();

    $result = playToResult($slug)->html();

    expect(writtenTextMark($result, 'Your taste is close to the crowd.'))->toBe('auto')
        ->and(writtenTextMark($result, 'Taste maker!'))->toBe('auto')
        ->and(writtenTextMark($result, 'Pairs game!'))->toBe('auto');
});

it('lets a page of the site that an administrator writes read its own way, the site\'s name staying where the page puts it', function () {
    ProjectSettings::query()->sole()->forceFill([
        'site_name' => 'RateGuru!',
        'static_pages' => [...config('static-pages.defaults'), 'about' => ['en' => ['title' => 'About us?', 'content' => 'We rate photos.']]],
    ])->save();
    app(ProjectSettingsManager::class)->flush();

    $html = $this->get(route('pages.about'))->assertOk()->getContent();

    expect(writtenTextMark($html, 'About us?'))->toBe('auto')
        ->and(writtenTextMark($html, 'We rate photos.'))->toBe('auto')
        ->and(livewireDom($html)->query('//*[@data-testid="static-page"]/p/bdi[normalize-space(.) = "RateGuru!"]')->length)->toBe(1);
});

it('leaves text with no letter in it — digits, emoji, punctuation — in the page\'s direction, as a browser would lay it out left to right', function () {
    $post = Post::factory()->published()->create(['title' => '100% 2024!', 'description' => '😀 123.']);
    Comment::factory()->for($post)->create(['body' => '😂😂']);

    $html = $this->get(route('posts.show', $post))->assertOk()->getContent();

    foreach (['100% 2024!', '😀 123.', '😂😂'] as $text) {
        expect(writtenTextHolder($html, $text))->not->toBeNull("[{$text}] is on the page")
            ->and(readsItsOwnWayOrIsMissing($html, $text))->toBeFalse("[{$text}] has no letter: it keeps the page's direction");
    }
});

it('takes the direction of a game\'s text from the administrator\'s own words, a game\'s title or a result\'s name put into it reading its own way', function () {
    app()->setLocale($this->locale);
    $draft = publishableGameDraft(1);
    $draft['game']->forceFill(['title' => 'Pairs game!'])->save();
    GameResultProfile::query()->where('content_set_id', $draft['set']->id)->update(['name' => 'Taste maker!']);
    // A sentence of the page's language that starts with the game's title, as Urdu's "{game} میں میرا نتیجہ" does.
    $sentence = '{game} '.__('games.play.share_title', [], $this->locale);
    GameFlowStep::query()->where('flow_id', $draft['flow']->id)->where('type', 'landing')->update([
        'title' => '{game}',
        'body' => 'Play {game} now.',
        'body_translations' => [$this->locale => $sentence],
    ]);
    $draft['game'] = $draft['game']->fresh();
    liveGame($draft);

    $dom = livewireDom(Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('s', 40))->test(PlayGame::class, ['slug' => $draft['game']->slug])->call('init'));
    $lead = $dom->query('//*[@data-testid="game-step-landing"]/p[contains(@class, "rg-game__lead")]')->item(0);
    $title = $dom->query('//*[@data-testid="game-step-landing"]/h1')->item(0);

    // dir="auto" passes over what sits in a <bdi>: the sentence's own words decide.
    expect($lead?->getAttribute('dir'))->toBe('auto')
        ->and($dom->query('bdi[normalize-space(.) = "Pairs game!"]', $lead)->length)->toBe(1)
        // Nothing of the administrator's own to go by: the page's direction, the title reading its own way in it.
        ->and($title?->hasAttribute('dir'))->toBeFalse()
        ->and($dom->query('bdi[normalize-space(.) = "Pairs game!"]', $title)->length)->toBe(1);
});

it('draws the site\'s own words on a game\'s button in the page\'s direction when the administrator left the label empty, only the site\'s name reading its own way', function () {
    app()->setLocale($this->locale);
    $draft = publishableGameDraft(1);
    replaceFlowSteps($draft['flow'], [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult], settings: ['full_result' => ['onward' => 'feed']]);
    liveGame($draft);

    $dom = livewireDom(playToResult($draft['game']->slug));

    // Urdu's line starts with the site's name: a <bdi> round the whole of it would read it left to right.
    expect($dom->query('//*[@data-testid="game-onward-go"]')->length)->toBe(1)
        ->and($dom->query('//*[@data-testid="game-onward-go"]/bdi')->length)->toBe(0)
        ->and($dom->query('//*[@data-testid="game-onward-go"]/span/bdi[normalize-space(.) = "RateGuru"]')->length)->toBe(1);
});

it('says which post was restored with its title reading its own way in the sentence', function () {
    $owner = User::factory()->create(['locale' => $this->locale]);
    $post = Post::factory()->published()->for($owner)->create(['title' => 'Which one is better?']);
    app(DeletePostAction::class)->handle($owner, $post);
    app()->setLocale($this->locale);

    $html = Livewire::actingAs($owner)->test(RecentlyDeletedPostsPage::class)->call('restore', $post->id)->html();

    expect(livewireDom($html)->query('//*[@data-testid="recently-deleted-status"]/bdi[normalize-space(.) = "Which one is better?"]')->length)->toBe(1);
});

it('puts a post\'s title into a notification as text that reads its own way, and the @name with its mark in front', function () {
    $post = postWrittenInEnglish();
    $reader = User::factory()->create(['locale' => $this->locale]);
    $reader->notify(new FollowedAuthorPostedNotification($post, $post->user));

    $html = Livewire::actingAs($reader)->test(NotificationBell::class)->html();

    expect(writtenTextMark($html, 'Which one is better?'))->toBe('bdi')
        ->and(livewireDom($html)->query('//*[@data-testid="notification-item"]//bdi')->length)->toBe(1);
});

it('lets what a visitor wrote to the site read its own way in the email it sends', function () {
    $html = (new ContactMessageMail('Jane Photos', 'jane@example.test', 'A question?', 'Hello there!'))->render();

    expect(writtenTextMark($html, 'Jane Photos'))->toBe('bdi')
        ->and(writtenTextMark($html, 'A question?'))->toBe('bdi')
        ->and(writtenTextMark($html, 'Hello there!'))->toBe('auto');
});

it('isolates what people wrote in a line of the site, escaping all of it', function () {
    app()->setLocale('en');

    expect(IsolatedTranslation::line('ui.feed.no_results_description', ['search' => '<b>cats</b>?'])->toHtml())
        ->toBe('No posts match &quot;<bdi>&lt;b&gt;cats&lt;/b&gt;?</bdi>&quot;. Try a different search.')
        ->and(IsolatedTranslation::line('games.play.shares_detail', ['site' => 'Rate & Guru'], ['picked' => 60, 'other' => 40])->toHtml())
        ->toBe('60% upvoted your pick on <bdi>Rate &amp; Guru</bdi>, against 40% for the other.');
});

it('puts a game\'s title and a result\'s name into an administrator\'s text each reading its own way, escaping all of it, and takes the direction from the administrator\'s own words', function () {
    $text = StepText::written('{game} & {profile}: {matches}/{total}', ['{game}' => '<b>Pairs</b>!', '{profile}' => 'Taste maker'], ['{matches}' => '3', '{total}' => '5']);

    expect($text->toHtml())->toBe('<bdi>&lt;b&gt;Pairs&lt;/b&gt;!</bdi> &amp; <bdi>Taste maker</bdi>: 3/5')
        ->and($text->text)->toBe('<b>Pairs</b>! & Taste maker: 3/5')
        // Not a letter of the administrator's own: the page's direction.
        ->and($text->dir()->toHtml())->toBe('')
        ->and(StepText::written('You are {profile}!', ['{profile}' => 'Taste maker'])->dir()->toHtml())->toBe('dir="auto"')
        ->and(StepText::own(IsolatedTranslation::line('games.play.by_site', ['site' => 'RateGuru']))->dir()->toHtml())->toBe('')
        ->and(WrittenText::dir('😂😂 10/10!')->toHtml())->toBe('')
        ->and(WrittenText::dir('Nice one!')->toHtml())->toBe('dir="auto"');
});

it('reads a dialog\'s title its own way only when asked to: a post\'s title, never the site\'s own', function () {
    $written = Blade::render('<x-ui.modal title="Which one is better?" title-dir="auto">Picture</x-ui.modal>');
    $own = Blade::render('<x-ui.modal title="Share this post">Links</x-ui.modal>');
    $letterless = livewireDom(Blade::render('<x-ui.modal :title="$title" title-dir="auto">Picture</x-ui.modal>', ['title' => '100 & 200!']))->query('//h2')->item(0);

    expect(writtenTextMark($written, 'Which one is better?'))->toBe('auto')
        ->and(readsItsOwnWayOrIsMissing($own, 'Share this post'))->toBeFalse()
        ->and($letterless)->toBeInstanceOf(DOMElement::class)
        ->and($letterless->hasAttribute('dir'))->toBeFalse();
});

it('keys a game headline by its text and direction, so a step drawn in another\'s place gets a new headline element', function () {
    $key = fn (string $blade, array $data = []): string => livewireDom(Blade::render($blade, $data))->query('//h1')->item(0)->getAttribute('wire:key');

    $english = $key('<x-games.headline :text="$text" />', ['text' => StepText::written('Which one would you pick?')]);
    $sameAgain = $key('<x-games.headline :text="$text" />', ['text' => StepText::written('Which one would you pick?')]);
    $siteOwn = $key('<x-games.headline :text="$text" />', ['text' => StepText::own('Which one would you pick?')]);
    $another = $key('<x-games.headline :text="$text" />', ['text' => StepText::written('Pick the cuter one')]);

    expect($english)->toStartWith('game-headline-')
        ->and($sameAgain)->toBe($english)
        ->and($siteOwn)->not->toBe($english)
        ->and($another)->not->toBe($english);
});
