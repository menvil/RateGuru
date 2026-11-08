<?php

/*
 * A query parameter given as a list — ?search[]=x — is a value a form field
 * cannot show. Every page drawing one shows nothing in its place instead of
 * failing.
 */

it('draws a page with the header search when the search is given as a list', function () {
    $searches = livewireDom($this->get('/?search[]=cats')->assertOk()->getContent())
        ->query("//input[@type='search'][@name='search']");

    expect($searches->length)->toBeGreaterThan(0);

    // Every search box on the page, the header's among them, empty: not "cats", not "Array".
    foreach ($searches as $search) {
        expect($search->getAttribute('value'))->toBe('');
    }
});

it('draws the password reset page when the address is given as a list', function () {
    $email = livewireDom($this->get('/reset-password/a-token?email[]=someone@example.com')->assertOk()->getContent())
        ->query("//form[contains(@action, '/reset-password')]//input[@name='email']");

    expect($email->length)->toBe(1)
        ->and($email->item(0)->getAttribute('value'))->toBe('');
});
