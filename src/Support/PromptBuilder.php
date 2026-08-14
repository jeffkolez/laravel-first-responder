<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

/**
 * Turns an incident into the text a model sees.
 *
 * Separate from the diagnosticians so that swapping providers doesn't mean
 * rewriting the prompt, and so the prompt itself can be asserted against in a
 * test. It is the part most likely to be quietly wrong.
 *
 * Two choices worth knowing about:
 *
 *  - The instruction permits "I don't know". Models will otherwise produce a
 *    confident, fluent, wrong cause, which sends somebody to the wrong file
 *    while the site is down.
 *  - Output is capped hard in words. This is read on a phone.
 */
final class PromptBuilder
{
    public function __construct(
        private readonly int $maxFrames = 3,
        private readonly int $wordLimit = 80,
    ) {
    }

    public function system(): string
    {
        return 'You are an experienced Laravel and PHP engineer triaging a '
            . 'production error. You are terse, concrete, and you say when you '
            . 'do not know.';
    }

    public function user(Incident $incident): string
    {
        $parts = [
            'An error occurred in production.',
            '',
            'TYPE: ' . $incident->type,
            'MESSAGE: ' . $incident->message,
        ];

        if ($incident->url !== null && $incident->url !== '') {
            $parts[] = 'URL: ' . $incident->url;
        }

        if ($incident->environment !== null && $incident->environment !== '') {
            $parts[] = 'ENVIRONMENT: ' . $incident->environment;
        }

        if ($incident->release !== null && $incident->release !== '') {
            $parts[] = 'RELEASE: ' . $incident->release;
        }

        foreach ($incident->significantFrames($this->maxFrames) as $i => $frame) {
            $parts[] = '';
            $parts[] = sprintf(
                'FRAME %d: %s%s',
                $i + 1,
                $frame->location(),
                $frame->function === null ? '' : ' in ' . $frame->function . '()'
            );

            $context = $frame->context();

            if ($context !== '') {
                $parts[] = $context;
            }
        }

        if ($incident->context !== []) {
            $encoded = json_encode($incident->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if (is_string($encoded) && mb_strlen($encoded) <= 2000) {
                $parts[] = '';
                $parts[] = 'CONTEXT:';
                $parts[] = $encoded;
            }
        }

        $parts[] = '';
        $parts[] = sprintf(
            'In no more than %d words, plain text, no markdown, no preamble: give the '
            . 'most likely cause and the single change that would fix it. Name the file '
            . 'and line. Some values above may show as %s. That is deliberate '
            . 'secret-scrubbing, so do not treat it as the bug. If the information above '
            . 'is not enough to be reasonably sure, begin your answer with "UNSURE:" and '
            . 'say what you would need to look at instead of guessing.',
            $this->wordLimit,
            Redactor::MASK
        );

        return implode("\n", $parts);
    }

    /**
     * Split a raw model answer into confidence + prose.
     *
     * The "UNSURE:" convention is how the model is allowed to admit ignorance
     * without that admission being lost in the formatting.
     *
     * @return array{0: string, 1: bool}  [summary, confident]
     */
    public function interpret(string $raw): array
    {
        $trimmed = ltrim($raw);

        if (stripos($trimmed, 'UNSURE:') === 0) {
            return [trim(substr($trimmed, 7)), false];
        }

        return [trim($raw), true];
    }
}
