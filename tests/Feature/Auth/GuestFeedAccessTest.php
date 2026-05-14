<?php

it('allows guests to see the feed route', function () {
    $this->get('/')->assertOk()->assertSee('RateGuru');
});
