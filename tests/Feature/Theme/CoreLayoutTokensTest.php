<?php

it('app layout header uses rg token classes', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->toContain('bg-rg-topbar');
    expect($layout)->toContain('border-rg-border');
    expect($layout)->toContain('bg-rg-bg');
});
