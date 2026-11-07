<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/*
 * Livewire 4 loads a component lazily for `lazy` or `defer` on its tag. It
 * reads `wire:lazy` as a plain HTML attribute, so a component marked with it
 * renders with the page while the view says otherwise.
 */
it('marks no component wire:lazy, which Livewire renders with the page', function () {
    $marked = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/^\s*wire:lazy\b/m', $file->getContents()) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($marked)->toBe([]);
});
