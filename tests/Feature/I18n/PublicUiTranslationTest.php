<?php

it('renders feed interface in Russian locale', function () {
    $this->withSession(['locale' => 'ru'])
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('Популярные')
        ->assertSee('Войти');
});

it('renders feed interface in Bulgarian locale', function () {
    $this->withSession(['locale' => 'bg'])
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('Популярни')
        ->assertSee('Вход');
});

it('renders feed interface in English by default', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('Hot')
        ->assertSee('Log in');
});
