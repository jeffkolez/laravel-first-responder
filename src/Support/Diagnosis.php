<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

/**
 * What the diagnostician concluded.
 *
 * `summary` is prose meant to be read on a phone, so it is short by contract
 * and not only by convention. The prompt asks for brevity and this enforces it:
 * a model that ignores the word limit should not be able to flood a chat
 * channel.
 *
 * `confident` exists so a report can be honest about not knowing. A diagnosis
 * that says "not enough information, look at X" is useful. One that invents a
 * plausible-sounding cause sends somebody down the wrong path while production
 * is down.
 */
final class Diagnosis
{
    public const MAX_SUMMARY_LENGTH = 600;

    public function __construct(
        public readonly string $summary,
        public readonly bool $confident = true,
        public readonly ?string $model = null,
    ) {
    }

    public static function make(string $summary, bool $confident = true, ?string $model = null): self
    {
        $summary = trim(preg_replace('/\s+/u', ' ', $summary) ?? $summary);

        if (mb_strlen($summary) > self::MAX_SUMMARY_LENGTH) {
            $summary = mb_substr($summary, 0, self::MAX_SUMMARY_LENGTH - 1) . '…';
        }

        return new self($summary, $confident, $model);
    }

    public function isEmpty(): bool
    {
        return trim($this->summary) === '';
    }
}
