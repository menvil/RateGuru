<?php

use App\Support\ContentLifecycle\CommentRetention;
use App\Support\ContentLifecycle\ModerationContentRetention;

/*
 * Fail-closed retention resolution for the PR-G cleanup boundaries.
 * Disabled must never degrade to "0 days"; invalid config must throw
 * before any destructive path computes a cutoff.
 */

it('accepts valid comment author retention values', function (mixed $configured, int $expected) {
    config(['content_lifecycle.comments.author_delete_retention_days' => $configured]);

    expect(CommentRetention::authorDeleteDays())->toBe($expected);
})->with([
    'default-shaped int' => [30, 30],
    'zero' => [0, 0],
    'digit string' => ['45', 45],
    'zero string' => ['0', 0],
    'leading zeroes' => ['030', 30],
]);

it('fails closed on invalid comment author retention values', function (mixed $configured) {
    config(['content_lifecycle.comments.author_delete_retention_days' => $configured]);

    expect(fn () => CommentRetention::authorDeleteDays())->toThrow(InvalidArgumentException::class);
})->with([
    'negative int' => [-1],
    'negative string' => ['-30'],
    'non-numeric' => ['foo'],
    'decimal' => ['1.5'],
    'null' => [null],
    'empty string' => [''],
    'beyond PHP_INT_MAX' => ['9223372036854775808'],
]);

it('treats empty moderation retention as disabled, never as zero', function (mixed $configured) {
    config(['content_lifecycle.moderation.content_retention_days' => $configured]);

    expect(ModerationContentRetention::days())->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
]);

it('accepts valid enabled moderation retention values', function (mixed $configured, int $expected) {
    config(['content_lifecycle.moderation.content_retention_days' => $configured]);

    expect(ModerationContentRetention::days())->toBe($expected);
})->with([
    'explicit zero' => [0, 0],
    'zero string' => ['0', 0],
    'ninety' => [90, 90],
    'ninety string' => ['90', 90],
]);

it('fails closed on invalid moderation retention values', function (mixed $configured) {
    config(['content_lifecycle.moderation.content_retention_days' => $configured]);

    expect(fn () => ModerationContentRetention::days())->toThrow(InvalidArgumentException::class);
})->with([
    'negative int' => [-1],
    'negative string' => ['-1'],
    'non-numeric' => ['foo'],
    'decimal' => ['1.5'],
    'boolean-like' => [true],
    'beyond PHP_INT_MAX' => ['9223372036854775808'],
]);
