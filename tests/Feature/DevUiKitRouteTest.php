<?php

it('renders the dev ui kit in local and testing environments', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('RateGuru UI Kit')
        ->assertSee('PlateRate Reference Composition')
        ->assertSee('Buttons')
        ->assertSee('Cards')
        ->assertSee('Forms')
        ->assertSee('Overlays')
        ->assertSee('Feedback')
        ->assertSee('Reference');

    $this->app->detectEnvironment(fn () => 'local');

    $this->get('/dev/ui-kit')->assertOk();
});

it('does not expose the dev ui kit in production-like environments', function () {
    $this->app->detectEnvironment(fn () => 'production');

    $this->get('/dev/ui-kit')->assertNotFound();
});

it('renders button variants in the dev ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Primary Button')
        ->assertSee('Secondary Button')
        ->assertSee('Ghost Button')
        ->assertSee('Danger Button')
        ->assertSee('Disabled Button');
});

it('renders card variants in the dev ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Panel Card')
        ->assertSee('Post Card Shell')
        ->assertSee('Selected Post Card Shell')
        ->assertSee('Results Card')
        ->assertSee('Comment Card');
});

it('renders modal shell in the dev ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Open Modal')
        ->assertSee('Upload Post Preview');
});

it('renders drawer shell in the dev ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Open Drawer')
        ->assertSee('Post Details Preview')
        ->assertSee('Which option fits best?');
});

it('renders form controls in the dev ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Post title')
        ->assertSee('Description')
        ->assertSee('Validation error example')
        ->assertSee('Disabled input');
});

it('renders the PlateRate reference composition in the UI kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('PlateRate Reference Composition')
        ->assertSee('Home')
        ->assertSee('CATEGORIES')
        ->assertSee('TOP TAGS')
        ->assertSee('Which option fits best?')
        ->assertSee('SAMPLE POST · IMAGE 01')
        ->assertSee('What do you think?')
        ->assertSee('Choose a category:')
        ->assertSee('Results')
        ->assertSee('Category distribution')
        ->assertSee('Comments');
});

it('renders the core PlateRate reference regions', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('data-ui="platerate-shell"', false)
        ->assertSee('data-ui="platerate-topbar"', false)
        ->assertSee('data-ui="platerate-sidebar"', false)
        ->assertSee('data-ui="platerate-feed"', false)
        ->assertSee('data-ui="post-card"', false)
        ->assertSee('data-ui="vote-rail"', false)
        ->assertSee('data-ui="image-placeholder"', false)
        ->assertSee('data-ui="platerate-detail-column"', false)
        ->assertSee('data-ui="results-panel"', false)
        ->assertSee('data-ui="comments-panel"', false);
});

it('renders post card example in the feed components section of ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Feed Components')
        ->assertSee('Sample Post')
        ->assertSee('Configurable rating')
        ->assertSee('data-testid="rating-option-1"', false);
});

it('renders comment item example in ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Comment Item')
        ->assertSee('Looks delicious.');
});
