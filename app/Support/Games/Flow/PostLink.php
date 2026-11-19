<?php

namespace App\Support\Games\Flow;

/**
 * What an administrator pasted to link a post to a flow step: a post of this
 * site — its address (`https://{host}/posts/{id}` or `/posts/{id}`), `#id`
 * or a bare id — another site's address, or words to look a post up by.
 */
final readonly class PostLink
{
    private function __construct(
        public ?int $postId,
        public ?string $foreignHost,
        public string $words,
    ) {}

    public static function parse(string $text, string $host): self
    {
        $text = trim($text);

        if (preg_match('/\A#?([1-9][0-9]{0,17})\z/', $text, $id) === 1) {
            return new self((int) $id[1], null, '');
        }

        if (preg_match('#\A/posts/([1-9][0-9]{0,17})/?(?:[?\#].*)?\z#', $text, $path) === 1) {
            return new self((int) $path[1], null, '');
        }

        if (preg_match('#\Ahttps?://#i', $text) === 1) {
            $linkHost = strtolower((string) parse_url($text, PHP_URL_HOST));
            $linkPath = (string) parse_url($text, PHP_URL_PATH);

            if ($linkHost !== strtolower($host)) {
                return new self(null, $linkHost, '');
            }

            return preg_match('#\A/posts/([1-9][0-9]{0,17})/?\z#', $linkPath, $own) === 1
                ? new self((int) $own[1], null, '')
                : new self(null, null, '');
        }

        return new self(null, null, $text);
    }

    public function isPost(): bool
    {
        return $this->postId !== null;
    }

    public function isForeign(): bool
    {
        return $this->foreignHost !== null;
    }
}
