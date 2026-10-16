<?php

use App\Models\User;

it('offers the default theme options on the Defaults tab of the project settings page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin/project-settings?tab=defaults')
        ->assertOk()
        ->assertSee('Default theme')
        ->assertSee('System')
        ->assertSee('Light')
        ->assertSee('Dark')
        ->assertSee('system')
        ->assertSee('light')
        ->assertSee('dark');
});
