<?php

it('serves feed page on home route', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('RateGuru')
        ->assertSee('Discover dishes');
});
