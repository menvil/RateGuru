<?php

use App\Enums\TrafficChannel;
use App\Support\Analytics\AnalyticsSource;

/*
 * The key of where a session came from: a paid source by its id; a known
 * network by the referrer's host or the utm_source tag, with the class of its
 * traffic by utm_medium — tagged and untagged traffic of one network and
 * class sharing a key, a paid class a key of its own; an unknown tag or host
 * by itself; a shared result, nothing at all and a move inside the site by
 * their own keys. And how each key reads.
 */

it('names a paid click by its source, and the arrivals with nothing to name them by alike', function () {
    expect(AnalyticsSource::keyFor(TrafficChannel::Network, 7, 'google.com'))->toBe('paid:7')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Placement, 12, null))->toBe('paid:12')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Shared, null, 'reddit.com'))->toBe('shared')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Internal, null, 'rateguru.test'))->toBe('internal')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Direct, null, null))->toBe('direct')
        // An in-app browser sends no referrer: nothing names where it came from.
        ->and(AnalyticsSource::keyFor(TrafficChannel::Referral, null, null))->toBe('direct');
});

it('knows the networks of the dictionary by their hosts and subdomains', function (string $host, string $key) {
    expect(AnalyticsSource::keyFor(TrafficChannel::Referral, null, $host))->toBe($key);
})->with([
    ['reddit.com', 'reddit:organic'],
    ['old.reddit.com', 'reddit:organic'],
    ['out.reddit.com', 'reddit:organic'],
    ['www.reddit.com', 'reddit:organic'],
    ['facebook.com', 'facebook:organic'],
    ['l.facebook.com', 'facebook:organic'],
    ['m.facebook.com', 'facebook:organic'],
    ['instagram.com', 'instagram:organic'],
    ['l.instagram.com', 'instagram:organic'],
    ['t.co', 'x:organic'],
    ['x.com', 'x:organic'],
    ['twitter.com', 'x:organic'],
    ['mobile.twitter.com', 'x:organic'],
    ['bing.com', 'bing:organic'],
    ['duckduckgo.com', 'duckduckgo:organic'],
    ['com.reddit.frontpage', 'reddit:organic'],
    ['com.google.android.googlequicksearchbox', 'google:organic'],
    ['REDDIT.COM', 'reddit:organic'],
]);

it('knows Google and Yandex on every country domain of theirs, and nothing that merely starts like them', function (string $host, string $key) {
    expect(AnalyticsSource::keyFor(TrafficChannel::Search, null, $host))->toBe($key);
})->with([
    ['google.com', 'google:organic'],
    ['www.google.de', 'google:organic'],
    ['google.co.uk', 'google:organic'],
    ['www.google.com.au', 'google:organic'],
    ['images.google.fr', 'google:organic'],
    ['yandex.ru', 'yandex:organic'],
    ['yandex.com.tr', 'yandex:organic'],
    ['google.example.com', 'host.example.com:organic'],
    ['notgoogle.com', 'host.notgoogle.com:organic'],
]);

it('reads the class of a network’s traffic from utm_medium', function (?string $medium, string $key) {
    expect(AnalyticsSource::keyFor(TrafficChannel::Direct, null, null, 'google', $medium))->toBe($key);
})->with([
    [null, 'google:organic'],
    ['cpc', 'google:paid'],
    ['PPC', 'google:paid'],
    ['paid_search', 'google:paid'],
    ['paidsocial', 'google:paid'],
    ['display', 'google:paid'],
    ['cpm', 'google:paid'],
    ['email', 'google:email'],
    ['referral', 'google:referral'],
    ['social', 'google:organic'],
    ['  ', 'google:organic'],
]);

it('gives tagged and untagged traffic of one network and class one key, and its paid traffic another', function () {
    $byReferrer = AnalyticsSource::keyFor(TrafficChannel::Referral, null, 'old.reddit.com');
    $byTag = AnalyticsSource::keyFor(TrafficChannel::Direct, null, null, 'reddit');
    $byBoth = AnalyticsSource::keyFor(TrafficChannel::Referral, null, 'out.reddit.com', 'Reddit', 'social');
    $paid = AnalyticsSource::keyFor(TrafficChannel::Search, null, 'google.com', 'google', 'cpc');

    expect([$byReferrer, $byTag, $byBoth])->each->toBe('reddit:organic')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Search, null, 'www.google.co.uk'))->toBe('google:organic')
        ->and($paid)->toBe('google:paid')
        // A tag names the network even when the referrer says another.
        ->and(AnalyticsSource::keyFor(TrafficChannel::Referral, null, 'reddit.com', 'fb'))->toBe('facebook:organic')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Direct, null, null, 'm.facebook.com'))->toBe('facebook:organic');
});

it('sends email by an email network unless the tags say otherwise', function () {
    expect(AnalyticsSource::keyFor(TrafficChannel::Direct, null, null, 'newsletter'))->toBe('email:email')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Direct, null, null, 'email', 'email'))->toBe('email:email')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Direct, null, null, 'newsletter', 'cpc'))->toBe('email:paid')
        // An email by its medium alone, with no source tag.
        ->and(AnalyticsSource::keyFor(TrafficChannel::Direct, null, null, null, 'email'))->toBe('email:email')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Direct, null, null, null, 'cpc'))->toBe('direct');
});

it('keeps an unknown tag and an unknown host as they are, the host by the domain it is registered under', function () {
    expect(AnalyticsSource::keyFor(TrafficChannel::Direct, null, null, 'Mailchimp', 'email'))->toBe('tag.mailchimp:email')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Direct, null, null, 'exo click', 'cpc'))->toBe('tag.exo click:paid')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Referral, null, 'news.ycombinator.com'))->toBe('host.ycombinator.com:organic')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Referral, null, 'www.Example.com'))->toBe('host.example.com:organic')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Referral, null, 'forum.example.co.uk'))->toBe('host.example.co.uk:organic')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Referral, null, '203.0.113.9'))->toBe('host.203.0.113.9:organic')
        // A tag and a host given by a referrer are two different places.
        ->and(AnalyticsSource::keyFor(TrafficChannel::Referral, null, 'blog.example.com', 'example'))->toBe('tag.example:organic')
        // Something that is no host at all names nothing.
        ->and(AnalyticsSource::keyFor(TrafficChannel::Referral, null, 'in-app browser'))->toBe('direct');
});

it('reads the search engine an unpaid visit’s origin names by itself', function () {
    expect(AnalyticsSource::keyFor(TrafficChannel::Search, null, 'google'))->toBe('google:organic')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Search, null, 'yandex'))->toBe('yandex:organic')
        ->and(AnalyticsSource::keyFor(TrafficChannel::Search, null, 'yahoo'))->toBe('host.yahoo:organic');
});

it('never writes a key longer than its column', function () {
    $tag = AnalyticsSource::keyFor(TrafficChannel::Direct, null, null, str_repeat('a', 300), 'cpc');
    $host = AnalyticsSource::keyFor(TrafficChannel::Referral, null, str_repeat('b', 200).'.com');

    expect(mb_strlen($tag))->toBe(140)
        ->and($tag)->toStartWith('tag.aaa')->toEndWith(':paid')
        ->and(mb_strlen($host))->toBeLessThanOrEqual(140)
        ->and($host)->toEndWith(':organic');
});

it('reads every key as its label, medium and group', function (string $key, string $label, string $medium, string $group) {
    expect([AnalyticsSource::label($key, [7 => 'ExoClick']), AnalyticsSource::medium($key), AnalyticsSource::group($key)])->toBe([$label, $medium, $group]);
})->with([
    ['paid:7', 'ExoClick', 'paid_ads', 'Paid'],
    ['paid:8', 'Paid source #8', 'paid_ads', 'Paid'],
    ['google:paid', 'Google Ads', 'paid_search', 'Paid'],
    ['google:organic', 'Google', 'organic_search', 'Organic'],
    ['instagram:paid', 'Instagram', 'paid_social', 'Paid'],
    ['reddit:organic', 'Reddit', 'organic_social', 'Organic'],
    ['facebook:referral', 'Facebook', 'referral', 'Referral'],
    ['email:email', 'Email', 'email', 'Email'],
    ['tag.mailchimp:email', 'mailchimp', 'email', 'Email'],
    ['tag.exo click:paid', 'exo click', 'paid_ads', 'Paid'],
    ['host.ycombinator.com:organic', 'ycombinator.com', 'referral', 'Organic'],
    ['host.example.com:referral', 'example.com', 'referral', 'Referral'],
    ['shared', 'Shared results', 'referral', 'Referral'],
    ['direct', 'Direct / Unknown', '(none)', 'Unattributed'],
    ['internal', 'Inside the site', '(none)', 'Inside the site'],
]);
