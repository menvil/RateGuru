<?php

it('does not allow guest to access admin panel', function () {
    $this->get('/admin')->assertRedirect();
});
