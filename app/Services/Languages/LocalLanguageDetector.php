<?php

namespace App\Services\Languages;

use App\Support\Languages\DetectedLanguage;
use App\Support\Languages\LanguageCodes;
use App\Support\Languages\TextCleaner;
use Nitotm\Eld\EldDataFile;
use Nitotm\Eld\EldMode;
use Nitotm\Eld\EldScheme;
use Nitotm\Eld\LanguageDetector;

/**
 * The language of a text, told at once and on this server: the efficient language detector (ELD) on the text's
 * words (TextCleaner), held to the languages the site installs. Used when a post or a comment is written; OpenAI
 * reads the text again a minute later (languages:work) and its answer replaces this one.
 *
 * The large database in bytes mode — about 24 MB and a millisecond to load, once per process, and only where a text
 * is written (composer keeps no other size: tools/composer/prune-language-detector-data.php). ELD does not know
 * Macedonian, Indonesian, Bosnian or Montenegrin and names a neighbour for them; OpenAI corrects that.
 */
final class LocalLanguageDetector
{
    private ?LanguageDetector $detector = null;

    /**
     * @param  list<string>  $installed  the site's language codes
     */
    public function __construct(private readonly array $installed) {}

    /**
     * The language of a raw text and how much it counts, or null when the text tells none.
     */
    public function detect(string $text): ?DetectedLanguage
    {
        $cleaned = TextCleaner::clean($text);

        if ($cleaned === '') {
            return null;
        }

        $result = $this->detector()->detect($cleaned);
        $language = $result->language === 'und' ? null : LanguageCodes::toSite($result->language, $this->installed);

        return $language === null ? null : new DetectedLanguage($language, TextCleaner::weight($cleaned));
    }

    private function detector(): LanguageDetector
    {
        if ($this->detector === null) {
            $this->detector = new LanguageDetector(EldDataFile::LARGE, EldScheme::ISO639_1, EldMode::MODE_BYTES);
            // A filter on its answers, not a database of its own: nothing is written to disk.
            $this->detector->langSubset(LanguageCodes::forDetector($this->installed), false);
        }

        return $this->detector;
    }
}
