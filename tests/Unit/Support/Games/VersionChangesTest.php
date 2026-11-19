<?php

use App\Data\Games\UnpublishedChanges;
use App\Enums\GameFlowGoal;
use App\Support\Games\Publishing\VersionChanges;
use App\Support\Games\Snapshot\GameVersionSnapshot;

/*
 * What a draft would change for players against the version they play, named
 * as the editors name it. Each case edits the stored shape of a snapshot — the
 * version's or the draft's — and compares the two.
 */

/**
 * A version of a Versus game: three rounds — two by the crowd, one by a
 * correct answer between two uploads — two result profiles and a flow from
 * the landing to a Full Result that sends players on to a post, then Go to
 * Page. The closure edits the stored shape first.
 *
 * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $edit
 */
function changesSnapshot(?Closure $edit = null): GameVersionSnapshot
{
    $post = fn (int $position, int $postId, int $up): array => ['position' => $position, 'source' => 'post', 'post_id' => $postId, 'asset_id' => $postId + 100, 'label' => [], 'upvotes' => $up, 'downvotes' => 100 - $up];
    $upload = fn (int $position, int $assetId): array => ['position' => $position, 'source' => 'upload', 'post_id' => null, 'asset_id' => $assetId, 'label' => ['en' => 'Option '.$position], 'upvotes' => 0, 'downvotes' => 0];
    $round = fn (string $key, string $mode, ?int $answer, array $options): array => [
        'key' => $key, 'question' => ['en' => 'Which one?', 'de' => 'Welches?'], 'description' => [], 'reveal' => ['en' => 'Most picked the left one.'],
        'mode' => $mode, 'scored' => true, 'show_crowd' => true, 'answer' => $answer, 'options' => $options,
    ];
    $fullResult = [
        'show_score' => true, 'show_profile' => true, 'share_enabled' => true, 'signup' => 'inline', 'onward' => 'post',
        'post_target' => 'last_pick', 'post_id' => null, 'ask_vote' => true, 'ask_groups' => '', 'auto_open' => false,
        'post_author' => true, 'post_votes' => true, 'post_likes' => true, 'post_comments' => true, 'feed_sort' => '',
        'destination' => 'category', 'category' => '', 'tag' => '', 'path' => '', 'url' => '', 'new_tab' => false,
    ];

    $data = [
        'schema' => 2,
        'game' => ['id' => 1, 'slug' => 'taste', 'mechanic' => 'versus', 'title' => ['en' => 'Trust Your Taste', 'de' => 'Vertrau deinem Geschmack'], 'description' => ['en' => 'Pick the photo you like more.'], 'cover_asset_id' => 5],
        'scoring' => ['points_per_round' => 1, 'crowd_min_votes' => 10, 'crowd_min_gap' => 5],
        'rounds' => [
            $round('r1', 'crowd', 0, [$post(0, 11, 90), $post(1, 12, 40)]),
            $round('r2', 'crowd', 1, [$post(0, 13, 30), $post(1, 14, 70)]),
            $round('r3', 'objective', 1, [$upload(0, 31), $upload(1, 32)]),
        ],
        'profiles' => [
            ['key' => 'sharp', 'name' => ['en' => 'Sharp eye'], 'description' => ['en' => 'You see it.'], 'min' => 0, 'max' => 1, 'icon' => 'award', 'share_text' => [], 'share_asset_id' => null],
            ['key' => 'twin', 'name' => ['en' => 'Crowd’s twin'], 'description' => [], 'min' => 2, 'max' => 3, 'icon' => 'sparkles', 'share_text' => [], 'share_asset_id' => null],
        ],
        'flow' => [
            'id' => 1,
            'name' => 'Result First',
            'goal' => 'post_voted',
            'steps' => [
                ['key' => 's1', 'type' => 'landing', 'audience' => 'all', 'settings' => ['show_branding' => true], 'texts' => ['title' => ['en' => 'Which would you pick?'], 'button_label' => ['en' => 'Start playing']]],
                ['key' => 's2', 'type' => 'game', 'audience' => 'all', 'settings' => ['advance' => 'auto', 'advance_seconds' => 2.5], 'texts' => []],
                ['key' => 's3', 'type' => 'full_result', 'audience' => 'all', 'settings' => $fullResult, 'texts' => ['title' => ['en' => 'Keep playing'], 'secondary_label' => ['en' => 'One more'], 'notice' => ['en' => 'Rate the post you picked.'], 'button_label' => ['en' => 'Rate it']]],
                ['key' => 's4', 'type' => 'go_to_page', 'audience' => 'all', 'settings' => ['destination' => 'category', 'category' => 'cats', 'tag' => '', 'path' => '', 'url' => '', 'new_tab' => false], 'texts' => ['title' => ['en' => 'More like this'], 'button_label' => ['en' => 'Take a look']]],
            ],
        ],
        'locales' => ['en'],
    ];

    return GameVersionSnapshot::fromArray($edit === null ? $data : $edit($data));
}

/** A step of the snapshot's flow, as stored, added at the end. */
function changesStep(string $key, string $type, array $texts = ['title' => ['en' => 'Share your result']]): array
{
    return ['key' => $key, 'type' => $type, 'audience' => 'all', 'settings' => [], 'texts' => $texts];
}

it('finds nothing between a version and a draft that is the same', function () {
    $version = changesSnapshot();
    $draft = changesSnapshot();

    expect(VersionChanges::game($version, $draft))->toBe([])
        ->and(VersionChanges::flow($version, $draft))->toBe([])
        ->and(VersionChanges::content($version, $draft))->toBe([]);
});

it('names the game’s title, description and cover as General names them, a title translated anew once', function (Closure $edit, array $changes) {
    expect(VersionChanges::game(changesSnapshot(), changesSnapshot($edit)))->toBe($changes);
})->with([
    'the title' => [function (array $s): array {
        $s['game']['title']['en'] = 'Trust Your Gut';

        return $s;
    }, ['Public title']],
    'the title, English and translation both' => [function (array $s): array {
        $s['game']['title'] = ['en' => 'Trust Your Gut', 'de' => 'Vertrau deinem Bauch'];

        return $s;
    }, ['Public title']],
    'a translation of the title alone' => [function (array $s): array {
        $s['game']['title']['fr'] = 'Fais confiance à ton goût';

        return $s;
    }, ['Public title · translations']],
    'the description and the cover' => [function (array $s): array {
        $s['game']['description'] = [];
        $s['game']['cover_asset_id'] = 6;

        return $s;
    }, ['Description', 'Cover image']],
]);

it('names a step’s text by its label in the Flow editor, a translation alone marked as such', function (Closure $edit, array $changes) {
    expect(VersionChanges::flow(changesSnapshot(), changesSnapshot($edit)))->toBe($changes);
})->with([
    'Full Result’s way-on title' => [function (array $s): array {
        $s['flow']['steps'][2]['texts']['secondary_label'] = ['en' => 'Not done yet'];

        return $s;
    }, ['Full Result · Way-on title']],
    'the landing’s hero title' => [function (array $s): array {
        $s['flow']['steps'][0]['texts']['title'] = ['en' => 'Pick one'];

        return $s;
    }, ['Landing · Hero title']],
    'a translation of the way-on button alone' => [function (array $s): array {
        $s['flow']['steps'][2]['texts']['button_label']['de'] = 'Bewerten';

        return $s;
    }, ['Full Result · Way-on button · translations']],
    'a text given where there was none' => [function (array $s): array {
        $s['flow']['steps'][0]['texts']['body'] = ['en' => 'A few quick pairs.'];

        return $s;
    }, ['Landing · Subtitle']],
]);

it('names a step’s settings by their labels in the Flow editor, a control holding several once', function (Closure $edit, array $changes) {
    expect(VersionChanges::flow(changesSnapshot(), changesSnapshot($edit)))->toBe($changes);
})->with([
    'Full Result’s way on' => [function (array $s): array {
        $s['flow']['steps'][2]['settings']['onward'] = 'feed';

        return $s;
    }, ['Full Result · Way on']],
    'where Go to Page leads' => [function (array $s): array {
        $s['flow']['steps'][3]['settings']['destination'] = 'tag';
        $s['flow']['steps'][3]['settings']['tag'] = 'dogs';

        return $s;
    }, ['Go to Page · Where it leads']],
    'the category it leads to' => [function (array $s): array {
        $s['flow']['steps'][3]['settings']['category'] = 'dogs';

        return $s;
    }, ['Go to Page · Category']],
    'the delay before the next pair' => [function (array $s): array {
        $s['flow']['steps'][1]['settings']['advance_seconds'] = 3.0;

        return $s;
    }, ['Game · Delay before the next pair']],
    'two of what the post’s card shows' => [function (array $s): array {
        $s['flow']['steps'][2]['settings']['post_votes'] = false;
        $s['flow']['steps'][2]['settings']['post_likes'] = false;

        return $s;
    }, ['Full Result · On the post\'s card']],
    'who sees a step' => [function (array $s): array {
        $s['flow']['steps'][3]['audience'] = 'anonymous';

        return $s;
    }, ['Go to Page · Show to']],
]);

it('leaves out what the step’s own choices hide in the Flow editor, and a setting stored before its default', function (Closure $version, Closure $draft) {
    expect(VersionChanges::flow(changesSnapshot($version), changesSnapshot($draft)))->toBe([]);
})->with([
    'the way-on title of a Full Result that goes on to the next step' => [
        function (array $s): array {
            $s['flow']['steps'][2]['settings']['onward'] = 'next';

            return $s;
        },
        function (array $s): array {
            $s['flow']['steps'][2]['settings']['onward'] = 'next';
            $s['flow']['steps'][2]['texts']['secondary_label'] = ['en' => 'Not done yet'];

            return $s;
        },
    ],
    'the post of a Full Result that sends players to their last pick' => [
        fn (array $s): array => $s,
        function (array $s): array {
            $s['flow']['steps'][2]['settings']['post_id'] = 77;

            return $s;
        },
    ],
    'the sign-up texts of a Full Result that sends players on' => [
        fn (array $s): array => $s,
        function (array $s): array {
            $s['flow']['steps'][2]['texts']['title'] = ['en' => 'Join us'];

            return $s;
        },
    ],
    'the delay of a game that waits for the button' => [
        function (array $s): array {
            $s['flow']['steps'][1]['settings']['advance'] = 'button';

            return $s;
        },
        function (array $s): array {
            $s['flow']['steps'][1]['settings'] = ['advance' => 'button', 'advance_seconds' => 4.0];

            return $s;
        },
    ],
    'a setting the version froze before it had a default' => [
        function (array $s): array {
            unset($s['flow']['steps'][2]['settings']['auto_open'], $s['flow']['steps'][2]['settings']['ask_groups']);

            return $s;
        },
        fn (array $s): array => $s,
    ],
    'a flow renamed' => [
        fn (array $s): array => $s,
        function (array $s): array {
            $s['flow']['name'] = 'Result First, renamed';

            return $s;
        },
    ],
]);

it('names the flow’s goal, its steps added, removed and put in another order', function (Closure $edit, array $changes) {
    expect(VersionChanges::flow(changesSnapshot(), changesSnapshot($edit)))->toBe($changes);
})->with([
    'the goal' => [function (array $s): array {
        $s['flow']['goal'] = 'page';

        return $s;
    }, ['Goal']],
    'a step added' => [function (array $s): array {
        $s['flow']['steps'][] = changesStep('n1', 'share');

        return $s;
    }, ['Share added']],
    'a step removed' => [function (array $s): array {
        unset($s['flow']['steps'][3]);

        return $s;
    }, ['Go to Page removed']],
    'two steps swapped' => [function (array $s): array {
        [$s['flow']['steps'][2], $s['flow']['steps'][3]] = [$s['flow']['steps'][3], $s['flow']['steps'][2]];

        return $s;
    }, ['Step order']],
    'a step added before the others, which keep their order' => [function (array $s): array {
        array_unshift($s['flow']['steps'], changesStep('n1', 'intro'));

        return $s;
    }, ['Intro added']],
    'a step that became another kind' => [function (array $s): array {
        $s['flow']['steps'][3] = changesStep('s4', 'feed', ['button_label' => ['en' => 'Go to the feed']]);

        return $s;
    }, ['Go to Feed added', 'Go to Page removed']],
]);

it('tells two steps of a kind apart by their place, as the Flow editor numbers them', function () {
    $two = function (array $s): array {
        $s['flow']['steps'][] = changesStep('n1', 'share');
        $s['flow']['steps'][] = changesStep('n2', 'share');

        return $s;
    };
    $retitled = function (array $s) use ($two): array {
        $s = $two($s);
        $s['flow']['steps'][5]['texts']['title'] = ['en' => 'Tell your friends'];

        return $s;
    };

    expect(VersionChanges::flow(changesSnapshot(), changesSnapshot($two)))->toBe(['Share (step 5) added', 'Share (step 6) added'])
        ->and(VersionChanges::flow(changesSnapshot($two), changesSnapshot($retitled)))->toBe(['Share (step 6) · Title']);
});

it('compares the goal a version that froze none counts, once it is known, and nothing without it', function () {
    $firstShape = function (array $s): array {
        $s['schema'] = 1;
        unset($s['flow']['goal']);

        return $s;
    };

    // The draft counts a vote on the post.
    expect(VersionChanges::flow(changesSnapshot($firstShape), changesSnapshot()))->toBe([])
        ->and(VersionChanges::flow(changesSnapshot($firstShape), changesSnapshot(), GameFlowGoal::Account))->toBe(['Goal'])
        ->and(VersionChanges::flow(changesSnapshot($firstShape), changesSnapshot(), GameFlowGoal::PostVoted))->toBe([])
        // A version that froze its goal is compared by it.
        ->and(VersionChanges::flow(changesSnapshot(), changesSnapshot(), GameFlowGoal::Account))->toBe([]);
});

it('names what a round says and shows, as the Content editor names it', function (Closure $edit, array $changes) {
    expect(VersionChanges::content(changesSnapshot(), changesSnapshot($edit)))->toBe($changes);
})->with([
    'the question' => [function (array $s): array {
        $s['rounds'][1]['question']['en'] = 'Which one, now?';

        return $s;
    }, ['Round 2 · Question']],
    'a translation of the question alone' => [function (array $s): array {
        $s['rounds'][1]['question']['de'] = 'Welches jetzt?';

        return $s;
    }, ['Round 2 · Question · translations']],
    'the description and the reveal message' => [function (array $s): array {
        $s['rounds'][0]['description'] = ['en' => 'Go with your gut.'];
        $s['rounds'][0]['reveal'] = [];

        return $s;
    }, ['Round 1 · Description', 'Round 1 · Reveal message']],
    'an option’s post, label and picture' => [function (array $s): array {
        $s['rounds'][0]['options'][1]['post_id'] = 15;
        $s['rounds'][2]['options'][0]['label'] = ['en' => 'Left'];
        $s['rounds'][2]['options'][1]['asset_id'] = 33;

        return $s;
    }, ['Round 1 · Option B · Post', 'Round 3 · Option A · Answer label', 'Round 3 · Option B · Picture']],
    'where an option’s picture comes from' => [function (array $s): array {
        $s['rounds'][1]['options'][0] = ['position' => 0, 'source' => 'upload', 'post_id' => null, 'asset_id' => 40, 'label' => [], 'upvotes' => 0, 'downvotes' => 0];

        return $s;
    }, ['Round 2 · Option A · Picture from']],
    'the correct answer, the mode, points and the upvote share' => [function (array $s): array {
        $s['rounds'][2]['answer'] = 0;
        $s['rounds'][1]['mode'] = 'objective';
        $s['rounds'][0]['scored'] = false;
        $s['rounds'][0]['answer'] = null;
        $s['rounds'][0]['show_crowd'] = false;

        return $s;
    }, ['Round 1 · Play without points', 'Round 1 · Show each post’s upvote share', 'Round 2 · Round mode', 'Round 3 · Correct answer']],
]);

it('leaves out the crowd’s votes and the pick they decide, which move by themselves', function () {
    $voted = function (array $s): array {
        $s['rounds'][0]['options'][0]['upvotes'] = 20;
        $s['rounds'][0]['options'][1]['upvotes'] = 95;
        $s['rounds'][0]['answer'] = 1;

        return $s;
    };

    expect(VersionChanges::content(changesSnapshot(), changesSnapshot($voted)))->toBe([]);
});

it('names rounds added, removed and put in another order by their place', function (Closure $edit, array $changes) {
    expect(VersionChanges::content(changesSnapshot(), changesSnapshot($edit)))->toBe($changes);
})->with([
    'two rounds swapped' => [function (array $s): array {
        [$s['rounds'][0], $s['rounds'][1]] = [$s['rounds'][1], $s['rounds'][0]];

        return $s;
    }, ['Round order']],
    'a round removed' => [function (array $s): array {
        array_splice($s['rounds'], 1, 1);

        return $s;
    }, ['Round 2 removed']],
    'a round added, then the one after it edited' => [function (array $s): array {
        array_splice($s['rounds'], 1, 0, [[...$s['rounds'][0], 'key' => 'r9']]);
        $s['rounds'][2]['question'] = ['en' => 'Which one, now?'];

        return $s;
    }, ['Round 2 added', 'Round 3 (was Round 2) · Question']],
]);

it('names the scoring and the result profiles, each profile by its name', function (Closure $edit, array $changes) {
    expect(VersionChanges::content(changesSnapshot(), changesSnapshot($edit)))->toBe($changes);
})->with([
    'points per match' => [function (array $s): array {
        $s['scoring']['points_per_round'] = 2;

        return $s;
    }, ['Scoring · Points per match']],
    'the crowd limits' => [function (array $s): array {
        $s['scoring']['crowd_min_votes'] = 20;
        $s['scoring']['crowd_min_gap'] = 0;

        return $s;
    }, ['Scoring · Site votes each post needs', 'Scoring · Gap the crowd pick needs']],
    'a profile renamed, its range and badge' => [function (array $s): array {
        $s['profiles'][0]['name'] = ['en' => 'Sharper eye'];
        $s['profiles'][0]['max'] = 2;
        $s['profiles'][0]['icon'] = 'eye';

        return $s;
    }, ['Profile “Sharper eye” · Name', 'Profile “Sharper eye” · Score range', 'Profile “Sharper eye” · Badge']],
    'a profile’s description, share text and share image' => [function (array $s): array {
        $s['profiles'][1]['description'] = ['en' => 'You and the crowd.'];
        $s['profiles'][1]['share_text'] = ['en' => 'I got {profile}.'];
        $s['profiles'][1]['share_asset_id'] = 50;

        return $s;
    }, ['Profile “Crowd’s twin” · Description', 'Profile “Crowd’s twin” · Share text', 'Profile “Crowd’s twin” · Share image']],
    'a profile added and one removed' => [function (array $s): array {
        $s['profiles'][1] = [...$s['profiles'][1], 'key' => 'lucky', 'name' => ['en' => 'Lucky one']];

        return $s;
    }, ['Profile “Lucky one” added', 'Profile “Crowd’s twin” removed']],
]);

it('names up to three changes, and past three the first three and how many more', function (int $count, string $headline, string $named, bool $more) {
    $changes = new UnpublishedChanges(['V2'], 1, 1, 2, 'V2', [
        ['title' => 'Content set “Standard”', 'changes' => array_map(fn (int $round): string => 'Round '.$round.' · Question', range(1, max(1, $count - 1)))],
        ['title' => 'Flow “Result First”', 'changes' => $count > 1 ? ['Full Result · Way-on title'] : []],
    ]);

    expect($changes->count())->toBe($count)
        ->and($changes->headline())->toBe($headline)
        ->and($changes->named())->toBe($named)
        ->and($changes->hasMore())->toBe($more);
})->with([
    'one' => [1, '1 change since V2', 'Round 1 · Question', false],
    'two' => [2, '2 changes since V2', 'Round 1 · Question, Full Result · Way-on title', false],
    'three' => [3, '3 changes since V2', 'Round 1 · Question, Round 2 · Question, Full Result · Way-on title', false],
    'five' => [5, '5 changes since V2', 'Round 1 · Question, Round 2 · Question, Round 3 · Question and 2 more', true],
]);

it('takes the rating groups a post asks as a set: the same groups in another order are no change', function () {
    $asking = fn (string $groups) => changesSnapshot(function (array $data) use ($groups): array {
        $data['flow']['steps'][2]['settings']['ask_groups'] = $groups;

        return $data;
    });

    expect(VersionChanges::flow($asking('looks,style'), $asking('style,looks')))->toBe([])
        ->and(VersionChanges::flow($asking('looks,style'), $asking('looks')))->toBe(['Full Result · Ask on the post']);
});

it('says where a kept round was when rounds before it were removed, so one name never means two rounds', function () {
    $draft = changesSnapshot(function (array $data): array {
        // Round 2 removed; the version's Round 3, now second, asks something else.
        unset($data['rounds'][1]);
        $data['rounds'] = array_values($data['rounds']);
        $data['rounds'][1]['question'] = ['en' => 'Which one, now?', 'de' => 'Welches?'];

        return $data;
    });

    expect(VersionChanges::content(changesSnapshot(), $draft))->toBe(['Round 2 (was Round 3) · Question', 'Round 2 removed']);
});

it('says where a kept step was when two steps of a kind changed places', function () {
    $twoShares = fn (array $order) => changesSnapshot(function (array $data) use ($order): array {
        $shares = ['a' => changesStep('sa', 'share', ['title' => ['en' => 'Share A']]), 'b' => changesStep('sb', 'share', ['title' => ['en' => 'Share B']])];
        array_splice($data['flow']['steps'], 3, 0, [$shares[$order[0]], $shares[$order[1]]]);

        return $data;
    });
    $draft = $twoShares(['b', 'a']);
    $draft = GameVersionSnapshot::fromArray((function (array $data): array {
        foreach ($data['flow']['steps'] as $index => $step) {
            if ($step['key'] === 'sb') {
                $data['flow']['steps'][$index]['texts']['title'] = ['en' => 'Share B, changed'];
            }
        }

        return $data;
    })($draft->toArray()));

    expect(VersionChanges::flow($twoShares(['a', 'b']), $draft))->toContain('Share (step 4) (was step 5) · Title');
});
