<?php

use App\Support\Analytics\TrackingLinkUrl;

/*
 * A tracking link's address: the path's own parameters kept, any utm_ ones
 * it carried replaced by the link's tags, each encoded, an empty tag left
 * out; a destination typed by hand read as a path or a full https address
 * on the site's host; and the name a link takes until it is named.
 */

it('puts the tags after the path, each encoded, an empty one left out', function () {
    expect(TrackingLinkUrl::compose('https://rateguru.com', '/games/trust-your-taste', [
        'utm_source' => 'reddit',
        'utm_medium' => 'organic_social',
        'utm_campaign' => 'versus_launch',
        'utm_content' => 'post_01',
        'utm_term' => 'taste quiz & more',
    ]))->toBe('https://rateguru.com/games/trust-your-taste?utm_source=reddit&utm_medium=organic_social&utm_campaign=versus_launch&utm_content=post_01&utm_term=taste%20quiz%20%26%20more')
        ->and(TrackingLinkUrl::compose('https://rateguru.com/', '/', ['utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'weekly', 'utm_content' => '', 'utm_term' => null]))
        ->toBe('https://rateguru.com/?utm_source=email&utm_medium=email&utm_campaign=weekly');
});

it('keeps the path’s own parameters, replaces its utm_ ones and drops what follows #', function () {
    expect(TrackingLinkUrl::compose('https://rateguru.com', '/?category%5B0%5D=dogs&UTM_source=old&utm_campaign=old&sort=top#top', ['utm_source' => 'reddit', 'utm_medium' => 'referral', 'utm_campaign' => 'new']))
        ->toBe('https://rateguru.com/?category%5B0%5D=dogs&sort=top&utm_source=reddit&utm_medium=referral&utm_campaign=new')
        ->and(TrackingLinkUrl::withoutTags('/posts/12?utm_source=x&ref=a#c'))->toBe('/posts/12?ref=a')
        ->and(TrackingLinkUrl::withoutTags('?utm_term=x'))->toBe('/')
        ->and(TrackingLinkUrl::gamePath('trust-your-taste'))->toBe('/games/trust-your-taste');
});

it('reads a destination typed by hand as a path of the site, or a full https address on its host', function (string $typed, ?string $path, ?string $error) {
    expect(TrackingLinkUrl::customPath($typed, 'rateguru.com'))->toBe(['path' => $path, 'error' => $error]);
})->with([
    'a path' => ['/?sort=top', '/?sort=top', null],
    'a path with its own tags' => [' /posts/7?utm_source=old&x=1 ', '/posts/7?x=1', null],
    'a full address' => ['https://RateGuru.com/u/anna?tab=posts', '/u/anna?tab=posts', null],
    'the site itself' => ['https://rateguru.com', '/', null],
    'a full address with a query but no path' => ['https://rateguru.com?sort=top', '/?sort=top', null],
    'nothing' => ['  ', null, 'Enter a destination.'],
    'spaces' => ['/c/my dogs', null, 'Invalid URL: remove the spaces.'],
    'another site' => ['https://example.com/x', null, 'Invalid URL. Use https:// and an address on rateguru.com, or a path like /?sort=top.'],
    'not https' => ['http://rateguru.com/x', null, 'Invalid URL. Use https:// and an address on rateguru.com, or a path like /?sort=top.'],
    'no leading slash' => ['c/dogs', null, 'Invalid URL. Use a path that starts with /, such as /?sort=top, or a full https://rateguru.com/… address.'],
    'another host by //' => ['//example.com/x', null, 'Invalid URL. Use a path that starts with /, such as /?sort=top, or a full https://rateguru.com/… address.'],
    'the admin' => ['/admin/analytics', null, 'Point it at a page visitors see, not the admin.'],
    'too long' => ['/'.str_repeat('a', 255), null, 'Up to 255 characters.'],
]);

it('names a link by its source, campaign and content until it is named', function () {
    expect(TrackingLinkUrl::autoName('reddit', 'versus_launch', 'post_01'))->toBe('Reddit · versus_launch · post_01')
        ->and(TrackingLinkUrl::autoName('tiktok', 'spring', ''))->toBe('tiktok · spring')
        ->and(TrackingLinkUrl::autoName('', ' ', ''))->toBe('')
        ->and(mb_strlen(TrackingLinkUrl::autoName('email', str_repeat('c', 120), str_repeat('d', 120))))->toBe(TrackingLinkUrl::MAX_NAME);
});

it('takes a source and a campaign written in lower-case letters, digits, - and _ only', function () {
    expect(TrackingLinkUrl::isToken('versus_launch-2'))->toBeTrue()
        ->and(TrackingLinkUrl::isToken('Versus'))->toBeFalse()
        ->and(TrackingLinkUrl::isToken('two words'))->toBeFalse()
        ->and(TrackingLinkUrl::isToken('_leading'))->toBeFalse()
        ->and(TrackingLinkUrl::sourceLabel('instagram'))->toBe('Instagram')
        ->and(TrackingLinkUrl::sourceLabel('tiktok'))->toBe('tiktok');
});
