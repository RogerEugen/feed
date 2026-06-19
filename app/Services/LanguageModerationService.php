<?php

namespace App\Services;

use Illuminate\Support\Str;

class LanguageModerationService
{
    public function __construct(private readonly ?array $blockedTerms = null)
    {
    }

    public function inspect(string $content): array
    {
        $normalized = $this->normalize($content);
        $matches = [];

        foreach ($this->blockedTerms ?? config('language.blocked_terms', []) as $term) {
            $needle = $this->normalize($term);
            if ($needle !== '' && $this->containsTerm($normalized, $needle)) {
                $matches[] = $term;
            }
        }

        return [
            'violates' => $matches !== [],
            'matches' => array_values(array_unique($matches)),
        ];
    }

    private function normalize(string $value): string
    {
        $value = Str::lower(Str::ascii($value));
        $value = preg_replace('/(.)\1{2,}/u', '$1$1', $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        $value = preg_replace_callback(
            '/(?:\b[a-z]\b\s*){3,}/',
            fn (array $match) => str_replace(' ', '', trim($match[0])) . ' ',
            $value
        );

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function containsTerm(string $content, string $term): bool
    {
        return preg_match(
            '/(?:^|\s)' . preg_quote($term, '/') . '(?:$|\s)/u',
            $content
        ) === 1;
    }
}
