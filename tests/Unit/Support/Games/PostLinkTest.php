<?php

use App\Support\Games\Flow\PostLink;

it('reads a post of this site from what was pasted', function (string $text, ?int $id) {
    expect(PostLink::parse($text, 'rateguru.test')->postId)->toBe($id);
})->with([
    'its address' => ['https://rateguru.test/posts/42', 42],
    'its address, any case of host' => ['https://RateGuru.test/posts/42/', 42],
    'its path' => ['/posts/42', 42],
    'its path with a query' => ['/posts/42?vote=1', 42],
    '#id' => ['#42', 42],
    'a bare id' => [' 42 ', 42],
    'a zero' => ['0', null],
    'another path of this site' => ['https://rateguru.test/feed', null],
    'words' => ['cute cats', null],
]);

it('tells another site from words to look up', function () {
    $foreign = PostLink::parse('https://example.com/posts/42', 'rateguru.test');
    $words = PostLink::parse('  cute cats ', 'rateguru.test');

    expect($foreign->isForeign())->toBeTrue()
        ->and($foreign->foreignHost)->toBe('example.com')
        ->and($foreign->isPost())->toBeFalse()
        ->and($words->isForeign())->toBeFalse()
        ->and($words->words)->toBe('cute cats');
});
