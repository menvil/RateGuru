<?php

it('renders feed interface in Russian locale', function () {
    $this->withSession(['locale' => 'ru'])
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('Новые')
        ->assertSee('Войти');
});

it('renders feed interface in Bulgarian locale', function () {
    $this->withSession(['locale' => 'bg'])
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('Нови')
        ->assertSee('Вход');
});

it('renders feed interface in English by default', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('Newest')
        ->assertSee('Log in');
});
