<?php

use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Queries\Feed\FeedQuery;

beforeEach(function () {
    $this->material = RatingGroup::factory()->create(['key' => 'material']);
    $this->wood = RatingOption::factory()->for($this->material, 'group')->create(['key' => 'wood']);
    $this->metal = RatingOption::factory()->for($this->material, 'group')->create(['key' => 'metal']);

    $this->size = RatingGroup::factory()->create(['key' => 'size']);
    $this->large = RatingOption::factory()->for($this->size, 'group')->create(['key' => 'large']);
    $this->small = RatingOption::factory()->for($this->size, 'group')->create(['key' => 'small']);
});

it('filters posts by an author answer in a rating group', function () {
    $matching = postWithAnswers([$this->wood]);
    postWithAnswers([$this->metal]);
    Post::factory()->published()->create();

    $results = app(FeedQuery::class)->get(ratingFilters: ['material' => ['wood']]);

    expect($results->pluck('id')->all())->toBe([$matching->id]);
});

it('combines options in one rating group with or', function () {
    $wood = postWithAnswers([$this->wood]);
    $metal = postWithAnswers([$this->metal]);
    postWithAnswers([$this->large]);

    $results = app(FeedQuery::class)->get(ratingFilters: [
        'material' => ['wood', 'metal'],
    ]);

    expect($results->pluck('id')->all())->toEqualCanonicalizing([$wood->id, $metal->id]);
});

it('combines different rating groups with and', function () {
    $matching = postWithAnswers([$this->wood, $this->large]);
    postWithAnswers([$this->wood, $this->small]);
    postWithAnswers([$this->metal, $this->large]);

    $results = app(FeedQuery::class)->get(ratingFilters: [
        'material' => ['wood'],
        'size' => ['large'],
    ]);

    expect($results->pluck('id')->all())->toBe([$matching->id]);
});

/** @param list<RatingOption> $options */
function postWithAnswers(array $options): Post
{
    $post = Post::factory()->published()->create();

    foreach ($options as $option) {
        $post->authorAnswers()->create([
            'rating_group_id' => $option->rating_group_id,
            'rating_option_id' => $option->id,
        ]);
    }

    return $post;
}
