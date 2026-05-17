<?php

it('serves feed page on home route', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('RateGuru')
        ->assertSee('Discover dishes');
});

it('renders base feed layout with section title', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Latest dishes');
});
