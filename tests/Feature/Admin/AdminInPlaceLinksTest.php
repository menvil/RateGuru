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
    // A whole tag: quoted values and PHP's => and -> inside Blade do not end it.
    $tag = '/<(?:a|x-admin\.ui\.button|x-admin\.ui\.menu-item)\b(?:"[^"]*"|\'[^\']*\'|[=-]>|[^>"\'])*>/';

    $offenders = collect(File::allFiles(resource_path('views/filament')))
        ->filter(function (SplFileInfo $file) use ($tag): bool {
            preg_match_all($tag, $file->getContents(), $tags);

            return collect($tags[0])->contains(fn (string $link): bool => str_contains($link, 'href') && str_contains($link, 'wire:click.prevent'))
                // Links built from attribute arrays, as the tabs component takes them.
                || str_contains($file->getContents(), "'wire:click.prevent' =>");
        })
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
