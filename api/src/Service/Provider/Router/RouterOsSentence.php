<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

/** A typed RouterOS API sentence. Values are sent as separate API words, never shell text. */
final readonly class RouterOsSentence
{
    /** @param non-empty-list<string> $words */
    public function __construct(public array $words)
    {
        if ($words === [] || $words[0] === '' || str_starts_with($words[0], '=')) {
            throw new \InvalidArgumentException('RouterOS sentence requires a command as its first word.');
        }
        foreach ($words as $word) {
            if (!is_string($word) || str_contains($word, "\0")) {
                throw new \InvalidArgumentException('RouterOS sentence words must be strings without null bytes.');
            }
        }
    }

    public function __debugInfo(): array
    {
        $words = $this->words;
        foreach ($words as &$word) {
            if (preg_match('/^=password=/', $word) === 1) {
                $word = '=password=[redacted]';
            }
        }
        unset($word);

        return ['words' => $words];
    }

    public function __serialize(): array
    {
        throw new \LogicException('RouterOS API sentences must never be serialized.');
    }
}
