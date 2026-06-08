<?php

it('renders feed interface in Russian locale', function () {
    $this->withSession(['locale' => 'ru'])
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('Источник')
        ->assertSee('Категория');
});

it('renders feed interface in Bulgarian locale', function () {
    $this->withSession(['locale' => 'bg'])
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('Източник')
        ->assertSee('Категория');
});

it('renders feed interface in English by default', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('Source')
        ->assertSee('Category');
});
