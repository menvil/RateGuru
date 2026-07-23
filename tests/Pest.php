<?php

use App\Models\RatingGroup;
use App\Models\RatingOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Create two configurable Rating Groups used by feed filter tests.
 */
function seedFeedFilterGroups(): void
{
    $type = RatingGroup::factory()->create(['key' => 'type', 'sort_order' => 10]);
    RatingOption::factory()->create(['rating_group_id' => $type->id, 'key' => 'type_a', 'sort_order' => 10]);
    RatingOption::factory()->create(['rating_group_id' => $type->id, 'key' => 'type_b', 'sort_order' => 20]);

    $attribute = RatingGroup::factory()->create(['key' => 'attribute', 'sort_order' => 20]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_a', 'sort_order' => 10]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_b', 'sort_order' => 20]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_c', 'sort_order' => 30]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_d', 'sort_order' => 40]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_other', 'sort_order' => 50]);
}
