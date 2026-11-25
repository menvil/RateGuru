<?php

namespace Database\Seeders\Support;

/**
 * A post of LanguageDemoSeeder's month while it is played: its author, its id once written, and who voted on it.
 */
final class DemoLanguagePost
{
    public ?int $id = null;

    /** @var list<int> */
    public array $voters = [];

    public function __construct(public readonly int $author) {}
}
