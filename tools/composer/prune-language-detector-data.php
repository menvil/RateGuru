<?php

/*
 * The language detector (nitotm/efficient-language-detector) ships every size of its database in two formats,
 * 172 MB in all, while App\Services\Languages\LocalLanguageDetector reads one of them: the large database in bytes
 * mode. Run after every autoload dump (composer.json), so whatever vendor directory composer installs — a fresh
 * one, CI's cached one, a release build's — keeps only that, about 21 MB. Running it twice changes nothing.
 */

$ngrams = __DIR__.'/../../vendor/nitotm/efficient-language-detector/resources/ngrams';

if (! is_dir($ngrams)) {
    return;
}

$kept = ['blob/large.php', 'blob/large.index.php', 'blob/large.data.php'];

foreach ([...(glob($ngrams.'/*.php') ?: []), ...(glob($ngrams.'/blob/*.php') ?: [])] as $file) {
    if (! in_array(substr($file, strlen($ngrams) + 1), $kept, true)) {
        unlink($file);
    }
}
