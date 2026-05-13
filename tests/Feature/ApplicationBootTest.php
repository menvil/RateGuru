<?php

it('loads the home page', function () {
    $this->get('/')->assertOk();
});
