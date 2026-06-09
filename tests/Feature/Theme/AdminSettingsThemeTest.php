<?php

use App\Models\User;

it('project settings page exposes default theme options', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin/project-settings')
        ->assertOk()
        ->assertSee('System')
        ->assertSee('Light')
        ->assertSee('Dark');
});

it('project settings form has default theme select', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin/project-settings')
        ->assertOk()
        ->assertSee('Default theme');
});

it('filament project settings page has system light dark options in html', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get('/admin/project-settings');

    $response->assertOk()
        ->assertSee('system')
        ->assertSee('light')
        ->assertSee('dark');
});
