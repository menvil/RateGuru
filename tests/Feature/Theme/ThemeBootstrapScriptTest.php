<?php

it('app layout includes theme bootstrap script inline', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->toContain('theme-bootstrap.js');
});
