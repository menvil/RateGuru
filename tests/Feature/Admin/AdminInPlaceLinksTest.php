<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/*
 * An admin link that opens its target in place — an editor in a drawer — is
 * a real address too, so it can be opened in a new tab. Livewire's
 * wire:click.prevent would cancel a click with a modifier key as well; such a
 * link checks for one and leaves that click to the browser.
 */
it('never cancels a click with a modifier key on an admin link that opens in place', function () {
    $offenders = collect(File::allFiles(resource_path('views/filament')))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/<(a|x-admin\.ui\.button|x-admin\.ui\.menu-item)\b[^>]*href[^>]*wire:click\.prevent/', $file->getContents()) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
