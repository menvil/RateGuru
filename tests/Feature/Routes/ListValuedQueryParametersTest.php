<?php

/*
 * A query parameter given as a list — ?search[]=x — is a value a form field
 * cannot show. Every page drawing one shows nothing in its place instead of
 * failing.
 */

it('draws a page with the header search when the search is given as a list', function () {
    $this->get('/?search[]=cats')->assertOk();
});

it('draws the password reset page when the address is given as a list', function () {
    $this->get('/reset-password/a-token?email[]=someone@example.com')
        ->assertOk()
        ->assertSee('name="email"', false);
});
