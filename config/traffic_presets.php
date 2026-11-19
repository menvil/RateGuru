<?php

/*
 * Ad network presets for Growth → Traffic sources: data, not code
 * (docs/architecture/traffic.md, "Presets"). A preset fills a new source
 * with the network's link tokens, its postback address and what is still to
 * fill in; the source keeps a copy, so a later change here never touches
 * it. Only the labels — name, formats, what to fill in, the window note and
 * what the network's API offers — are read live. A new network gets a
 * preset once someone has set it up and checked it with a test postback;
 * an API connector is code of its own, added later.
 *
 * Every value is a literal; PresetSchema checks the whole file.
 */

return [

    'presets' => [

        'adsterra' => [
            'name' => 'Adsterra',
            'link_key' => 'adst',
            'formats' => ['Popunder', 'Social bar', 'Native', 'Banner'],
            'connector' => null,
            'network_api' => 'statistics and blacklists',
            'attribution_days' => 7,
            'click_id_ttl_hours' => null,
            'window_note' => '',
            'reporting_timezone' => 'UTC',
            'cost_unit' => 'cpm',
            'link_params' => [
                ['kind' => 'click', 'label' => 'Click ID', 'param' => 'cid', 'token' => '##SUB_ID_SHORT(action)##', 'note' => 'Needed for postbacks'],
                ['kind' => 'campaign', 'label' => 'Campaign', 'param' => 'campaign', 'token' => '##CAMPAIGN_ID##', 'note' => ''],
                ['kind' => 'site', 'label' => 'Site (Adsterra zone)', 'param' => 'site', 'token' => '##ZONE_ID##', 'note' => ''],
                ['kind' => 'zone', 'label' => 'Placement', 'param' => 'zone', 'token' => '##PLACEMENT_ID##', 'note' => ''],
                ['kind' => 'creative', 'label' => 'Creative', 'param' => 'creative', 'token' => '##BANNER_ID##', 'note' => ''],
                ['kind' => 'cost', 'label' => 'Click cost', 'param' => 'cost', 'token' => '##COST_CPM##', 'note' => 'Spend per 1000 clicks'],
            ],
            'postback' => [
                'url_template' => 'https://www.pbterra.com/name/…/at?subid_short={click_id}',
                'response_rules' => [],
                'otherwise' => 'ok',
            ],
            'fill' => 'Your Adsterra username in the postback address, in place of … (it is case-sensitive). On CPC campaigns use ##COST_CPC## for the click cost. Adsterra reads atpay as what you pay it for a conversion, not what the conversion is worth to you, so the address leaves it out: on a CPA campaign, add &atpay= with your CPA price, never {value}.',
            'custom' => false,
        ],

        'clickadu' => [
            'name' => 'Clickadu',
            'link_key' => 'cadu',
            'formats' => ['Popunder', 'In-page push', 'Video'],
            'connector' => null,
            'network_api' => 'statistics',
            'attribution_days' => 7,
            'click_id_ttl_hours' => null,
            'window_note' => '',
            'reporting_timezone' => 'UTC',
            'cost_unit' => 'cpm',
            'link_params' => [
                ['kind' => 'click', 'label' => 'Click ID', 'param' => 'cid', 'token' => '${SUBID}', 'note' => 'Needed for postbacks'],
                ['kind' => 'campaign', 'label' => 'Campaign', 'param' => 'campaign', 'token' => '{campaignid}', 'note' => ''],
                ['kind' => 'zone', 'label' => 'Zone ID', 'param' => 'zone', 'token' => '{zoneid}', 'note' => ''],
                ['kind' => 'creative', 'label' => 'Creative', 'param' => 'creative', 'token' => '{bannerid}', 'note' => ''],
                ['kind' => 'cost', 'label' => 'Click cost', 'param' => 'cost', 'token' => '{cost}', 'note' => 'SmartCPM and CPM campaigns'],
            ],
            'postback' => [
                'url_template' => 'https://sconvtrk.com/conversion/…/?visitor_id={click_id}&aid=…',
                'response_rules' => [],
                'otherwise' => 'ok',
            ],
            'fill' => 'The hash and aid from the tracker postback under Tracking in Clickadu. Clickadu takes no value, so postbacks only say that the step happened.',
            'custom' => false,
        ],

        'exoclick' => [
            'name' => 'ExoClick',
            'link_key' => 'exo',
            'formats' => ['Popunder', 'Native', 'Push', 'Banner', 'In-page'],
            'connector' => 'exoclick',
            'network_api' => 'statistics and blacklists',
            'attribution_days' => 7,
            'click_id_ttl_hours' => null,
            'window_note' => 'ExoClick keeps a goal’s conversion window at 24 hours unless you raise it: set it to the source’s window under Campaigns → Conversions Tracking.',
            'reporting_timezone' => 'America/New_York',
            'cost_unit' => 'click',
            'link_params' => [
                ['kind' => 'click', 'label' => 'Click ID', 'param' => 'cid', 'token' => '{conversions_tracking}', 'note' => 'Needed for postbacks'],
                ['kind' => 'campaign', 'label' => 'Campaign', 'param' => 'campaign', 'token' => '{campaign_id}', 'note' => ''],
                ['kind' => 'site', 'label' => 'Site ID', 'param' => 'site', 'token' => '{site_id}', 'note' => ''],
                ['kind' => 'zone', 'label' => 'Zone ID', 'param' => 'zone', 'token' => '{zone_id}', 'note' => ''],
                ['kind' => 'creative', 'label' => 'Creative', 'param' => 'creative', 'token' => '{variation_id}', 'note' => ''],
                ['kind' => 'cost', 'label' => 'Click cost', 'param' => 'cost', 'token' => '{actual_cost}', 'note' => 'Spend per click'],
            ],
            'postback' => [
                'url_template' => 'https://s.magsrv.com/tag.php?goal={goal_code}&tag={click_id}&value={value}',
                // ExoClick answers 200 to everything: the body says what happened.
                'response_rules' => [
                    ['outcome' => 'ok', 'match' => 'equals', 'text' => 'OK'],
                    ['outcome' => 'duplicate', 'match' => 'contains', 'text' => 'already been notified'],
                    ['outcome' => 'rejected', 'match' => 'contains', 'text' => 'Conversion window is not respected'],
                    ['outcome' => 'rejected', 'match' => 'contains', 'text' => 'Tag is invalid'],
                    ['outcome' => 'rejected', 'match' => 'contains', 'text' => 'No tag data'],
                    ['outcome' => 'rejected', 'match' => 'contains', 'text' => 'Goal ID is invalid'],
                    ['outcome' => 'rejected', 'match' => 'contains', 'text' => 'No Goal ID'],
                ],
                'otherwise' => 'retry',
            ],
            'fill' => 'A goal ID for each postback, from Campaigns → Conversions Tracking (set the goal to Dynamic value so it takes the worth), and an API token.',
            'custom' => false,
        ],

        'juicyads' => [
            'name' => 'JuicyAds',
            'link_key' => 'juicy',
            'formats' => ['Banner', 'Popunder', 'Native'],
            'connector' => null,
            'network_api' => 'statistics for popunders only',
            'attribution_days' => 7,
            'click_id_ttl_hours' => null,
            'window_note' => '',
            'reporting_timezone' => 'UTC',
            'cost_unit' => 'click',
            'link_params' => [
                ['kind' => 'click', 'label' => 'Click ID', 'param' => 'cid', 'token' => '{dynamicS2S}', 'note' => 'Needed for postbacks'],
                ['kind' => 'campaign', 'label' => 'Campaign', 'param' => 'campaign', 'token' => '{dynamicCMP}', 'note' => ''],
                ['kind' => 'site', 'label' => 'Site ID', 'param' => 'site', 'token' => '{dynamicSITE}', 'note' => ''],
                ['kind' => 'zone', 'label' => 'Zone ID', 'param' => 'zone', 'token' => '{dynamicZNE}', 'note' => ''],
                ['kind' => 'creative', 'label' => 'Creative', 'param' => 'creative', 'token' => '{dynamicIMG}', 'note' => ''],
            ],
            'postback' => [
                'url_template' => '',
                'response_rules' => [],
                'otherwise' => 'ok',
            ],
            'fill' => 'The postback address from Settings → Performance Tracking → S2S Postbacks, with {click_id} after s2s= and {value} after amount=. JuicyAds passes no click cost, so spend comes from your rate.',
            'custom' => false,
        ],

        'popads' => [
            'name' => 'PopAds',
            'link_key' => 'pop',
            'formats' => ['Popunder'],
            'connector' => 'popads',
            'network_api' => 'statistics and blacklists',
            'attribution_days' => 2,
            'click_id_ttl_hours' => 48,
            'window_note' => 'PopAds keeps a click ID for about 48 hours, so a postback later than that is lost. Keep the window at 2 days or less.',
            'reporting_timezone' => 'UTC',
            // [BID] is the price of one impression, and one popunder impression is one visit.
            'cost_unit' => 'click',
            'link_params' => [
                ['kind' => 'click', 'label' => 'Click ID', 'param' => 'cid', 'token' => '[IMPRESSIONID]', 'note' => 'Needed for postbacks'],
                ['kind' => 'campaign', 'label' => 'Campaign', 'param' => 'campaign', 'token' => '[CAMPAIGNID]', 'note' => ''],
                ['kind' => 'site', 'label' => 'Website ID', 'param' => 'site', 'token' => '[WEBSITEID]', 'note' => ''],
                ['kind' => 'cost', 'label' => 'Click cost', 'param' => 'cost', 'token' => '[BID]', 'note' => 'Price of one impression'],
            ],
            'postback' => [
                'url_template' => 'https://serve.popads.net/cpixel.php?s2s=1&aid=…&id={click_id}&value={value}',
                // PopAds answers 200 with an empty body; an error code comes only in this header.
                'response_rules' => [
                    ['outcome' => 'rejected', 'match' => 'header', 'text' => 'X-PopAds-EC'],
                ],
                'otherwise' => 'ok',
                // PopAds answers every unknown impression ID, and so Send test's made-up one, with
                // X-PopAds-EC: InvalidEnvironmentException…, whatever the aid: only a real click's postback checks it.
                'test_note' => 'PopAds refuses any click ID it did not issue, so Send test always comes back rejected, even with a wrong aid. Check the rule with a real click: its postback shows as delivered in Logs → Postbacks.',
            ],
            'fill' => 'Your account’s aid from Conversions → Pixels and Postbacks, in the postback address.',
            'custom' => false,
        ],

        'propellerads' => [
            'name' => 'PropellerAds',
            'link_key' => 'prop',
            'formats' => ['Popunder', 'Push', 'In-page push', 'Interstitial'],
            'connector' => null,
            'network_api' => 'statistics and blacklists',
            'attribution_days' => 7,
            'click_id_ttl_hours' => null,
            'window_note' => '',
            'reporting_timezone' => 'UTC',
            'cost_unit' => 'click',
            'link_params' => [
                ['kind' => 'click', 'label' => 'Click ID', 'param' => 'cid', 'token' => '${SUBID}', 'note' => 'Needed for postbacks'],
                ['kind' => 'campaign', 'label' => 'Campaign', 'param' => 'campaign', 'token' => '{campaignid}', 'note' => ''],
                ['kind' => 'zone', 'label' => 'Zone ID', 'param' => 'zone', 'token' => '{zoneid}', 'note' => ''],
                ['kind' => 'creative', 'label' => 'Creative', 'param' => 'creative', 'token' => '{bannerid}', 'note' => ''],
                ['kind' => 'cost', 'label' => 'Click cost', 'param' => 'cost', 'token' => '{cost}', 'note' => 'Spend per click'],
            ],
            'postback' => [
                'url_template' => 'https://ad.propellerads.com/conversion.php?aid=…&pid=&tid=…&visitor_id={click_id}&payout={value}',
                'response_rules' => [],
                'otherwise' => 'ok',
            ],
            'fill' => 'aid and tid, shown filled in on the Tracking tab of PropellerAds. One help page spells the tokens {zone_id} and {campaign_id}; the link check shows which arrive.',
            'custom' => false,
        ],

        'trafficjunky' => [
            'name' => 'TrafficJunky',
            'link_key' => 'tj',
            'formats' => ['Banner', 'Native', 'Pre-roll', 'Popunder'],
            'connector' => null,
            'network_api' => 'campaign statistics; sources only as a list to rewrite',
            'attribution_days' => 7,
            'click_id_ttl_hours' => null,
            'window_note' => '',
            'reporting_timezone' => 'UTC',
            'cost_unit' => 'click',
            'link_params' => [
                ['kind' => 'click', 'label' => 'Click ID', 'param' => 'cid', 'token' => '{ACLID}', 'note' => 'Needed for postbacks'],
                ['kind' => 'campaign', 'label' => 'Campaign', 'param' => 'campaign', 'token' => '{CampaignID}', 'note' => ''],
                ['kind' => 'zone', 'label' => 'Spot ID', 'param' => 'zone', 'token' => '{SpotID}', 'note' => ''],
                ['kind' => 'creative', 'label' => 'Creative', 'param' => 'creative', 'token' => '{AdID}', 'note' => ''],
            ],
            'postback' => [
                'url_template' => '',
                'response_rules' => [],
                'otherwise' => 'ok',
            ],
            'fill' => 'The postback address from Tools → Conversion Tracker → View code, with {click_id} after aclid= and {value} after ctv=. TrafficJunky passes no site ID and no click cost, so spend comes from your rate.',
            'custom' => false,
        ],

        'trafficstars' => [
            'name' => 'TrafficStars',
            'link_key' => 'ts',
            'formats' => ['Popunder', 'Banner', 'Native', 'Video', 'In-page'],
            'connector' => null,
            'network_api' => 'statistics and blacklists',
            'attribution_days' => 7,
            'click_id_ttl_hours' => null,
            'window_note' => '',
            'reporting_timezone' => 'UTC',
            'cost_unit' => 'click',
            'link_params' => [
                ['kind' => 'click', 'label' => 'Click ID', 'param' => 'cid', 'token' => '{click_id}', 'note' => 'Needed for postbacks'],
                ['kind' => 'campaign', 'label' => 'Campaign', 'param' => 'campaign', 'token' => '{campaign_id}', 'note' => ''],
                ['kind' => 'site', 'label' => 'Site ID', 'param' => 'site', 'token' => '{site_id}', 'note' => ''],
                ['kind' => 'zone', 'label' => 'Ad spot ID', 'param' => 'zone', 'token' => '{adspot_id}', 'note' => ''],
                ['kind' => 'creative', 'label' => 'Creative', 'param' => 'creative', 'token' => '{creative_id}', 'note' => ''],
                ['kind' => 'cost', 'label' => 'Click cost', 'param' => 'cost', 'token' => '{cost}', 'note' => 'CPC, CPA and dynamic CPM'],
            ],
            'postback' => [
                'url_template' => 'https://tsyndicate.com/api/v1/cpa/action?key=…&clickid={click_id}&value={value}',
                'response_rules' => [],
                'otherwise' => 'ok',
            ],
            'fill' => 'Your postback key from Goals & Postbacks → Postback, in place of ….',
            'custom' => false,
        ],

        'custom' => [
            'name' => 'Other ad network',
            'link_key' => '',
            'formats' => ['Any network without a preset'],
            'connector' => null,
            'network_api' => '',
            'attribution_days' => 7,
            'click_id_ttl_hours' => null,
            'window_note' => '',
            'reporting_timezone' => 'UTC',
            'cost_unit' => 'click',
            'link_params' => [
                ['kind' => 'click', 'label' => 'Click ID', 'param' => 'cid', 'token' => '', 'note' => 'Needed for postbacks'],
                ['kind' => 'campaign', 'label' => 'Campaign', 'param' => 'campaign', 'token' => '', 'note' => ''],
                ['kind' => 'site', 'label' => 'Site ID', 'param' => 'site', 'token' => '', 'note' => ''],
            ],
            'postback' => [
                'url_template' => '',
                'response_rules' => [],
                'otherwise' => 'ok',
            ],
            'fill' => 'The network’s token for each parameter and its postback address. Its help pages or support list them.',
            'custom' => true,
        ],

    ],

    // The networks whose API RateGuru can talk to: code of its own for each
    // (App\Services\Traffic\Connectors). "verified" turns true once it has
    // been checked on a real account.
    'connectors' => [
        'exoclick' => [
            'name' => 'ExoClick API',
            'credential_label' => 'API token',
            'credential_help' => 'ExoClick → Account → API tokens',
            'promise' => 'RateGuru only reads statistics and edits campaign blacklists; it never changes bids or budgets.',
            'verified' => false,
        ],
        'popads' => [
            'name' => 'PopAds API',
            'credential_label' => 'API key',
            'credential_help' => 'PopAds → Account → API',
            'promise' => 'RateGuru only reads statistics and campaigns and edits website exclusion lists; it never changes bids, budgets or funds.',
            'verified' => false,
        ],
    ],

];
