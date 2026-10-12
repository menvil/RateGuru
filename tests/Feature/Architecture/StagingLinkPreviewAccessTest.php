<?php

use Illuminate\Support\Facades\File;

/*
 * Staging is behind a password, except for the bots that build link previews:
 * they fetch a shared page and its picture without credentials, so the vhost
 * lets them through by User-Agent. These tests read the committed vhost and
 * keep that opening to the preview bots, so a search engine never gets a
 * staging page to index.
 */

function stagingVhost(): string
{
    return File::get(base_path('infrastructure/config/nginx/rateguru-staging'));
}

/** The vhost's User-Agent pattern, as PCRE the way nginx's ~* applies it. */
function stagingLinkPreviewBotPattern(): string
{
    $matched = preg_match(
        '/^\s*if \(\$http_user_agent ~\* "([^"]+)"\) \{\n\s*set \$rateguru_staging_auth off;\n\s*\}$/m',
        stagingVhost(),
        $matches,
    );

    expect($matched)->toBe(1, 'the staging vhost no longer turns its password off by User-Agent');

    return '#'.$matches[1].'#i';
}

it('asks everyone the pattern does not match for the staging password', function () {
    preg_match_all('/^\s*(auth_basic\w*|set \$rateguru_staging_auth)\s+(.+);$/m', stagingVhost(), $directives, PREG_SET_ORDER);

    expect(array_map(fn (array $directive): string => $directive[1].' '.$directive[2], $directives))->toBe([
        'set $rateguru_staging_auth "RateGuru staging"',
        'set $rateguru_staging_auth off',
        'auth_basic $rateguru_staging_auth',
        'auth_basic_user_file /etc/nginx/rateguru-staging.htpasswd',
    ]);
});

it('lets the bots that build link previews through', function (string $userAgent) {
    expect(preg_match(stagingLinkPreviewBotPattern(), $userAgent))->toBe(1);
})->with([
    'Facebook, Messenger and Instagram' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    'iMessage' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_1) AppleWebKit/601.2.4 (KHTML, like Gecko) Version/9.0.1 Safari/601.2.4 facebookexternalhit/1.1 Facebot Twitterbot/1.0',
    'X' => 'Twitterbot/1.0',
    'Telegram' => 'TelegramBot (like TwitterBot)',
    'Slack, for the page' => 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
    'Slack, for its picture' => 'Slack-ImgProxy (+https://api.slack.com/robots)',
    'LinkedIn' => 'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)',
    'WhatsApp' => 'WhatsApp/2.23.20.0 A',
    'Discord' => 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)',
    'Reddit' => 'Mozilla/5.0 (compatible; redditbot/1.0; +http://www.reddit.com/feedback)',
    'Pinterest, for the picture a pin is made from' => 'Mozilla/5.0 (compatible; Pinterestbot/1.0; +http://www.pinterest.com/bot.html)',
    'VK' => 'Mozilla/5.0 (compatible; vkShare; +http://vk.com/dev/Share)',
]);

it('keeps search engines and everyone else behind the password', function (string $userAgent) {
    expect(preg_match(stagingLinkPreviewBotPattern(), $userAgent))->toBe(0);
})->with([
    'Google' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'Bing' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
    'Yandex' => 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
    'DuckDuckGo' => 'DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)',
    'Apple search' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)',
    'a browser' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
    'no User-Agent' => '',
]);
