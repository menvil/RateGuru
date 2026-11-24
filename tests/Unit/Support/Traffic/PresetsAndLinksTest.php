<?php

use App\Support\Traffic\Links\LinkInspector;
use App\Support\Traffic\Links\LinkParams;
use App\Support\Traffic\Links\TrackingLinkBuilder;
use App\Support\Traffic\Postbacks\PostbackTemplate;
use App\Support\Traffic\Presets\PresetSchema;
use App\Support\Traffic\Presets\SourceDefaults;
use Illuminate\Support\Arr;

const TRAFFIC_RESERVED_PARAMS = ['search', 'tag', 'category', 'ratings', 'sort', 'feed', 'tab', 'page', 'vote', 'ask'];

/** The presets file, read as the application reads it. */
function presetsFile(): array
{
    return include dirname(__DIR__, 4).'/config/traffic_presets.php';
}

it('keeps every network preset complete and usable', function () {
    $presets = presetsFile();

    expect(PresetSchema::violations($presets, TRAFFIC_RESERVED_PARAMS))->toBe([])
        ->and(array_keys($presets['presets']))->toBe(['adsterra', 'clickadu', 'exoclick', 'juicyads', 'popads', 'propellerads', 'trafficjunky', 'trafficstars', 'custom'])
        ->and($presets['presets']['popads']['attribution_days'])->toBe(2)
        ->and($presets['presets']['popads']['cost_unit'])->toBe('click')
        ->and($presets['presets']['exoclick']['reporting_timezone'])->toBe('America/New_York');
});

it('names what is wrong with a broken preset', function () {
    $presets = presetsFile();
    $presets['presets']['popads']['link_key'] = 'direct';
    $presets['presets']['popads']['attribution_days'] = 5;
    $presets['presets']['popads']['link_params'][] = ['kind' => 'click', 'label' => 'Again', 'param' => 'sort', 'token' => 'plain', 'note' => ''];
    $presets['presets']['popads']['connector'] = 'nowhere';
    $presets['presets']['exoclick']['postback']['url_template'] = 'https://10.0.0.1/tag.php?tag={tag}';

    expect(PresetSchema::violations($presets, TRAFFIC_RESERVED_PARAMS))->toBe([
        'preset exoclick: The address must name a host, such as postback.example.com, not an IP address.',
        'preset exoclick: {tag} isn’t a placeholder the site fills in.',
        'preset exoclick: Put {click_id} in the address: without it the network can’t tell which click converted.',
        'preset popads: link_key must be 2–40 of a-z, 0-9, _ and -, and not a channel\'s name',
        'preset popads: attribution_days must be one of 1, 2, 3, 7 or 30',
        'preset popads: connector nowhere is not described under connectors',
        'preset popads: link parameter 5: “sort” is read by the site’s own pages. Use another name.',
        'preset popads: link parameter 5: A token looks like {name}, [NAME], ##NAME## or ${NAME}, as the network writes it.',
        'preset popads: Exactly one parameter carries the click ID: postbacks need it.',
    ]);
});

it('accepts a postback address RateGuru can send, and says what is wrong with others', function (string $template, array $problems) {
    expect(PostbackTemplate::violations($template))->toBe($problems);
})->with([
    'ExoClick, filled in' => ['https://s.magsrv.com/tag.php?goal=8f2a&tag={click_id}&value={value}', []],
    'a parameter of the click' => ['https://pb.example.com/c?id={click_id}&zone={param:zone}', []],
    'gaps left' => ['https://www.pbterra.com/name/…/at?subid_short={click_id}', ['Fill in the parts marked … with your account’s values.']],
    'no click id' => ['https://pb.example.com/c?value={value}', ['Put {click_id} in the address: without it the network can’t tell which click converted.']],
    'an odd port' => ['https://pb.example.com:8443/c?id={click_id}', ['Only ports 80 and 443 can be used.']],
    'a password' => ['https://me:secret@pb.example.com/c?id={click_id}', ['The address can’t carry a user name or password.']],
    'not http' => ['ftp://pb.example.com/c?id={click_id}', ['The address must start with https:// or http://.']],
    'a fragment' => ['https://pb.example.com/c?id={click_id}#x', ['Remove the part after #: it never reaches the network.']],
]);

it('builds the link to paste with each token raw, and a banner’s with its creative', function () {
    $params = presetsFile()['presets']['popads']['link_params'];

    expect(TrackingLinkBuilder::forNetwork('https://rateguru.test/games/trust-your-taste', 'pop', $params))
        ->toBe('https://rateguru.test/games/trust-your-taste?rg_src=pop&cid=[IMPRESSIONID]&campaign=[CAMPAIGNID]&site=[WEBSITEID]&cost=[BID]')
        ->and(TrackingLinkBuilder::forPlacement('https://rateguru.test/?sort=top', 'blog', 'sidebar'))
        ->toBe('https://rateguru.test/?sort=top&rg_src=blog&rg_cr=sidebar');
});

it('starts a source from its preset: the copy, spend from the API where a connector reads it, and the goal rule kept off', function () {
    $presets = presetsFile()['presets'];
    $pop = SourceDefaults::forPreset('popads', $presets['popads'], true, ['pop']);
    $juicy = SourceDefaults::forPreset('juicyads', $presets['juicyads'], false, []);
    $exo = SourceDefaults::forPreset('exoclick', $presets['exoclick'], true, []);

    expect($pop['source']['link_key'])->toBe('pop2')
        ->and($pop['source']['connector'])->toBe('popads')
        ->and($pop['source']['spend_mode'])->toBe('api')
        ->and($pop['source']['click_id_ttl_hours'])->toBe(48)
        ->and($pop['source']['block_level'])->toBe('site')
        ->and($pop['rules'][0])->toMatchArray(['event' => 'goal', 'enabled' => false])
        ->and($juicy['source']['spend_mode'])->toBe('rate')
        ->and($juicy['rules'])->toBe([])
        ->and($exo['source']['block_level'])->toBe('zone')
        ->and($exo['source']['response_rules']['otherwise'])->toBe('retry')
        ->and(SourceDefaults::forPreset('custom', $presets['custom'], false, [])['source']['name'])->toBe('');
});

it('checks a source’s link parameters', function () {
    expect(LinkParams::violations([
        ['kind' => 'click', 'label' => 'Click', 'param' => 'cid', 'token' => '{click}', 'note' => ''],
        ['kind' => 'campaign', 'label' => 'Campaign', 'param' => 'cid', 'token' => '', 'note' => ''],
        ['kind' => 'site', 'label' => '', 'param' => 'rg_site', 'token' => '', 'note' => ''],
    ], TRAFFIC_RESERVED_PARAMS))->toBe([
        ['index' => 1, 'field' => 'param', 'message' => '“cid” is already used by another parameter.'],
        ['index' => 2, 'field' => 'param', 'message' => '“rg_site” is read by the site’s own pages. Use another name.'],
        ['index' => 2, 'field' => 'label', 'message' => 'Give the parameter a name of up to 60 characters.'],
    ]);
});

it('refuses a token that would break the link it is pasted into', function () {
    $token = 'A token looks like {name}, [NAME], ##NAME## or ${NAME}, as the network writes it.';

    expect(LinkParams::violations([
        ['kind' => 'click', 'label' => 'Click', 'param' => 'cid', 'token' => '##SUB_ID_SHORT(action)##', 'note' => ''],
        ['kind' => 'campaign', 'label' => 'Campaign', 'param' => 'campaign', 'token' => '{a&b}', 'note' => ''],
        ['kind' => 'site', 'label' => 'Site', 'param' => 'site', 'token' => '[a b]', 'note' => ''],
        ['kind' => 'zone', 'label' => 'Zone', 'param' => 'zone', 'token' => '{a#b}', 'note' => ''],
        ['kind' => 'cost', 'label' => 'Cost', 'param' => 'cost', 'token' => '{a=b?}', 'note' => ''],
    ], TRAFFIC_RESERVED_PARAMS))->toBe([
        ['index' => 1, 'field' => 'token', 'message' => $token],
        ['index' => 2, 'field' => 'token', 'message' => $token],
        ['index' => 3, 'field' => 'token', 'message' => $token],
        ['index' => 4, 'field' => 'token', 'message' => $token],
    ]);
});

describe('checking a link', function () {
    $source = fn (string $key): ?array => match ($key) {
        'pop' => ['id' => 3, 'name' => 'PopAds', 'link_params' => presetsFile()['presets']['popads']['link_params'], 'api_spend' => true],
        'adst' => ['id' => 4, 'name' => 'Adsterra', 'link_params' => presetsFile()['presets']['adsterra']['link_params'], 'api_spend' => false],
        default => null,
    };
    $game = fn (string $slug): ?string => $slug === 'trust-your-taste' ? 'Trust Your Taste' : null;
    $campaign = fn (int $sourceId, string $id): ?array => $id === '881204' ? ['name' => 'US popunder', 'archived' => true] : null;

    it('tells a link to paste from a real click and from one with tokens left', function () use ($source, $game, $campaign) {
        $template = LinkInspector::inspect('https://rateguru.test/games/trust-your-taste?rg_src=pop&cid=[IMPRESSIONID]&campaign=[CAMPAIGNID]&site=[WEBSITEID]&cost=[BID]', 'rateguru.test', $source, $game, $campaign);
        $click = LinkInspector::inspect('/games/trust-your-taste?rg_src=pop&cid=9000123&campaign=881204&site=77&cost=0.0004', 'rateguru.test', $source, $game, $campaign);
        $mixed = LinkInspector::inspect('https://rateguru.test/games/trust-your-taste?rg_src=pop&cid=9000123&campaign=[CAMPAIGNID]', 'rateguru.test', $source, $game, $campaign);

        expect($template->mode)->toBe('template')
            ->and(Arr::last($template->warnings)['text'])->toBe('Paste it as the target URL of every PopAds campaign of Trust Your Taste. PopAds fills in each token on every click.')
            ->and($click->mode)->toBe('click')
            ->and($click->campaignId)->toBe('881204')
            ->and(Arr::last($click->warnings)['text'])->toBe('Counts for US popunder, which is archived: this click brings it back. Leads to Trust Your Taste.')
            ->and($mixed->mode)->toBe('mixed')
            ->and(array_column($mixed->fields, 'state'))->toBe(['ok', 'token', 'missing', 'missing']);
    });

    it('reads a network’s ##NAME## tokens as tokens, not as the start of a fragment', function () use ($source, $game, $campaign) {
        $built = LinkInspector::inspect(TrackingLinkBuilder::forNetwork('https://rateguru.test/games/trust-your-taste', 'adst', presetsFile()['presets']['adsterra']['link_params']), 'rateguru.test', $source, $game, $campaign);
        $typed = LinkInspector::inspect('https://rateguru.test/games/trust-your-taste?rg_src=adst&cid=##SUB_ID_SHORT(action)##&campaign=##CAMPAIGN_ID##&cost=##COST_CPM##', 'rateguru.test', $source, $game, $campaign);

        expect($built->mode)->toBe('template')
            ->and(array_column($built->fields, 'state'))->toBe(['token', 'token', 'token', 'token', 'token', 'token'])
            ->and($built->fields[0]['value'])->toBe('##SUB_ID_SHORT(action)##')
            ->and($built->extra)->toBe([])
            ->and($typed->mode)->toBe('template')
            ->and(array_column($typed->fields, 'state', 'param'))->toBe(['cid' => 'token', 'campaign' => 'token', 'site' => 'missing', 'zone' => 'missing', 'creative' => 'missing', 'cost' => 'token'])
            ->and($typed->campaignId)->toBeNull();
    });

    it('says what is missing from a broken one', function () use ($source, $game, $campaign) {
        $none = LinkInspector::inspect('https://elsewhere.test/feed', 'rateguru.test', $source, $game, $campaign);
        $unknown = LinkInspector::inspect('https://rateguru.test/games/nope?rg_src=exo', 'rateguru.test', $source, $game, $campaign);
        $noClick = LinkInspector::inspect('https://rateguru.test/games/trust-your-taste?rg_src=pop&campaign=5', 'rateguru.test', $source, $game, $campaign);
        // An empty click ID is no click ID: the capture drops it, so the check warns of it the same way.
        $emptyClick = LinkInspector::inspect('/games/trust-your-taste?rg_src=pop&cid=&campaign=5', 'rateguru.test', $source, $game, $campaign);

        expect($none->mode)->toBe('none')
            ->and(array_column($none->warnings, 'text'))->toBe([
                'The link opens elsewhere.test, not this site: no click on it reaches the site.',
                'The link doesn’t open a game. Visits still count for the source, but no game gets them.',
                'No rg_src in the link. The site can’t tell it’s paid: visits will count as unpaid (another site or direct).',
            ])
            ->and(array_column($unknown->warnings, 'text'))->toBe(['No game has the address “nope”.', 'No source has the key “exo”. Check the key under Sources, or add the source first.'])
            ->and(array_column($noClick->warnings, 'text'))->toContain('No click id (cid=[IMPRESSIONID]): postbacks can’t tell PopAds which click it was.')
            ->and(array_column($noClick->warnings, 'text'))->toContain('Campaign 5 isn’t known yet: this click adds it to the list as new, and it works at once.')
            ->and($emptyClick->fields[0])->toMatchArray(['param' => 'cid', 'state' => 'missing'])
            ->and(array_column($emptyClick->warnings, 'text'))->toContain('No click id (cid=[IMPRESSIONID]): postbacks can’t tell PopAds which click it was.');
    });
});
