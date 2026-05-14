<?php

it('allows guests to access the login page', function () {
    $this->get('/login')->assertOk()->assertSee('Email')->assertSee('Password');
});
