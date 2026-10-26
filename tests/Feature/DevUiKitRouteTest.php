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
        ->assertSee('Reference')
        // Button variants.
        ->assertSee('Primary Button')
        ->assertSee('Secondary Button')
        ->assertSee('Ghost Button')
        ->assertSee('Danger Button')
        ->assertSee('Disabled Button')
        // Card variants.
        ->assertSee('Panel Card')
        ->assertSee('Post Card Shell')
        ->assertSee('Selected Post Card Shell')
        ->assertSee('Results Card')
        ->assertSee('Comment Card')
        // Modal and drawer shells.
        ->assertSee('Open Modal')
        ->assertSee('Upload Post Preview')
        ->assertSee('Open Drawer')
        ->assertSee('Post Details Preview')
        ->assertSee('Which option fits best?')
        // Form controls.
        ->assertSee('Post title')
        ->assertSee('Description')
        ->assertSee('data-ui="search-form"', false)
        ->assertSee('Validation error example')
        ->assertSee('Disabled input')
        // A Persona's comment, marked as an AI persona's, and the badge alone.
        ->assertSee('Comment Item by a Persona')
        ->assertSee('Persona Badge')
        ->assertSee('data-testid="persona-badge"', false)
        // The PlateRate reference composition and its regions.
        ->assertSee('Home')
        ->assertSee('CATEGORIES')
        ->assertSee('TOP TAGS')
        ->assertSee('SAMPLE POST · IMAGE 01')
        ->assertSee('What do you think?')
        ->assertSee('Choose an attribute:')
        ->assertSee('Results')
        ->assertSee('Attribute distribution')
        ->assertSee('Comments')
        ->assertSee('data-ui="platerate-shell"', false)
        ->assertSee('data-ui="platerate-topbar"', false)
        ->assertSee('data-ui="platerate-sidebar"', false)
        ->assertSee('data-ui="platerate-feed"', false)
        ->assertSee('data-ui="post-card"', false)
        ->assertSee('data-ui="vote-rail"', false)
        ->assertSee('data-ui="image-placeholder"', false)
        ->assertSee('data-ui="platerate-detail-column"', false)
        ->assertSee('data-ui="results-panel"', false)
        ->assertSee('data-ui="comments-panel"', false)
        // Feed components.
        ->assertSee('Feed Components')
        ->assertSee('Sample Post')
        ->assertSee('Configurable rating')
        ->assertSee('data-testid="rating-option-1"', false)
        ->assertSee('Comment Item')
        ->assertSee('Great example.');

    $this->app->detectEnvironment(fn () => 'local');

    $this->get('/dev/ui-kit')->assertOk();
});

it('does not expose the dev ui kit in production-like environments', function () {
    $this->app->detectEnvironment(fn () => 'production');

    $this->get('/dev/ui-kit')->assertNotFound();
});
