<?php

namespace App\Services\Languages;

use App\Exceptions\Languages\LanguageDetectionFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * The languages of a batch of texts, as OpenAI reads them: the second, better reading of what the local detector
 * told when the texts were written (languages:work). Close languages are kept apart and transliterated text is read
 * as the language it is written in; a text that tells none — names, brands, words every language shares — is
 * answered as none.
 *
 * Its own prompt and response schema: the translation engine translates and nothing else
 * (docs/architecture/translation-engine.md). It uses the Translation AI account — its key, model and address from
 * config('translation.providers.openai') — and sends the texts' words only (TextCleaner), never links, handles or
 * addresses. Nothing is stored on OpenAI's side.
 */
final class OpenAiLanguageDetector
{
    /** Texts per request: a batch of mixed texts, so no one language tells the model the others'. */
    public const BATCH = 40;

    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 45;

    private const INSTRUCTIONS = 'You identify the language each short user-written text is written in, on a photo-rating website. '
        .'Answer with the ISO code from the allowed list. Close languages are different languages: Russian, Ukrainian, '
        .'Bulgarian, Macedonian and Serbian are distinct; so are Croatian, Bosnian, Montenegrin and Serbian in Latin script; '
        .'Czech and Slovak; Danish and Norwegian Bokmal (nb); Indonesian and Malay. Text transliterated into Latin letters '
        .'counts as the language it is written in (e.g. "krasivaya" is Russian). Names of places, brands, products, people '
        .'and accounts, and words used the same way in many languages (OK, wow, lol, selfie, love, top, super, Paris, '
        .'BMW M3, iPhone, Anna) do not tell a language: when a text has nothing else, answer "und". "other" is for a real '
        .'language not on the list. confidence is 0-1.';

    /**
     * @param  list<string>  $installed  the site's language codes
     */
    public function __construct(private readonly array $installed) {}

    /** Whether the Translation AI account it uses is set up on this installation. */
    public function configured(): bool
    {
        return $this->setting('api_key') !== null && $this->setting('model') !== null;
    }

    /**
     * The language of each text, or null for a text that tells none.
     *
     * @param  array<int, string>  $texts  keyed by the caller's id
     * @return array<int, array{language: string|null, confidence: float}> an entry for every id it answered
     *
     * @throws LanguageDetectionFailed
     */
    public function detect(array $texts): array
    {
        $key = $this->setting('api_key');
        $model = $this->setting('model');

        if ($key === null || $model === null || $texts === []) {
            throw LanguageDetectionFailed::because(LanguageDetectionFailed::UNAVAILABLE);
        }

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->post($this->endpoint(), $this->body($model, $texts));
        } catch (ConnectionException|RequestException) {
            throw LanguageDetectionFailed::because(LanguageDetectionFailed::UNAVAILABLE);
        }

        if ($response->serverError() || in_array($response->status(), [408, 429], true)) {
            throw LanguageDetectionFailed::because(LanguageDetectionFailed::UNAVAILABLE);
        }

        if (! $response->successful()) {
            throw LanguageDetectionFailed::because(LanguageDetectionFailed::UNUSABLE);
        }

        return $this->answers($response->json(), array_keys($texts));
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<string, mixed>
     */
    private function body(string $model, array $texts): array
    {
        $lines = [];

        foreach ($texts as $id => $text) {
            $lines[] = json_encode(['id' => (string) $id, 'text' => $text], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $codes = [...$this->installed, 'und', 'other'];

        return [
            'model' => $model,
            'store' => false,
            'instructions' => self::INSTRUCTIONS,
            'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => implode("\n", $lines)]]]],
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => 'languages',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['results'],
                    'properties' => ['results' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'language', 'confidence'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'language' => ['type' => 'string', 'enum' => $codes],
                            'confidence' => ['type' => 'number'],
                        ],
                    ]]],
                ],
            ]],
        ];
    }

    /**
     * The answers in a completed response: its one output text, read as the schema's results. A refusal, no text
     * or a text of another shape is no answer for the batch.
     *
     * @param  list<int>  $ids
     * @return array<int, array{language: string|null, confidence: float}>
     */
    private function answers(mixed $document, array $ids): array
    {
        $texts = [];

        foreach ((is_array($document) && is_array($document['output'] ?? null)) ? $document['output'] : [] as $item) {
            foreach ((is_array($item) && is_array($item['content'] ?? null)) ? $item['content'] : [] as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'refusal') {
                    throw LanguageDetectionFailed::because(LanguageDetectionFailed::UNUSABLE);
                }

                if (is_array($part) && ($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $texts[] = $part['text'];
                }
            }
        }

        $decoded = count($texts) === 1 ? json_decode($texts[0], true) : null;

        if (! is_array($decoded) || ! is_array($decoded['results'] ?? null)) {
            throw LanguageDetectionFailed::because(LanguageDetectionFailed::UNUSABLE);
        }

        $answers = [];

        foreach ($decoded['results'] as $result) {
            // Only the id exactly as it was sent ("7", never "7.9", " 7" or "07") answers a text.
            $raw = is_array($result) ? ($result['id'] ?? null) : null;
            $id = is_string($raw) && ctype_digit($raw) && (string) (int) $raw === $raw ? (int) $raw : null;

            if ($id === null || ! in_array($id, $ids, true) || ! is_string($result['language'] ?? null)) {
                continue;
            }

            $answers[$id] = [
                'language' => in_array($result['language'], $this->installed, true) ? $result['language'] : null,
                'confidence' => is_numeric($result['confidence'] ?? null) ? max(0.0, min(1.0, (float) $result['confidence'])) : 0.0,
            ];
        }

        return $answers;
    }

    private function endpoint(): string
    {
        $base = $this->setting('base_url') ?? 'https://api.openai.com/v1';

        if (! str_starts_with($base, 'https://')) {
            throw LanguageDetectionFailed::because(LanguageDetectionFailed::UNAVAILABLE);
        }

        return rtrim($base, '/').'/responses';
    }

    private function setting(string $name): ?string
    {
        $value = config("translation.providers.openai.{$name}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
