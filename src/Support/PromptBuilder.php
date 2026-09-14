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
            . 'production error. You are shown the failing line, the method '
            . 'that contains it, the bodies of the application methods it '
            . 'calls, and the real request that triggered it. Work out what '
            . 'happened from those. You are terse and concrete. You reach a '
            . 'conclusion; you do not hedge, and you do not ask to be shown '
            . 'things you have already been given.';
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
            $signature = $frame->signature();

            $parts[] = sprintf(
                'FRAME %d: %s%s',
                $i + 1,
                $frame->location(),
                $signature === null ? '' : ' in ' . $signature
            );

            $context = $frame->context();

            if ($context !== '') {
                $parts[] = $context;
            }
        }

        $facts = $incident->facts();

        if ($facts !== []) {
            $encoded = json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if (is_string($encoded) && mb_strlen($encoded) <= 2000) {
                $parts[] = '';
                $parts[] = 'REQUEST AND CONTEXT (the real values this ran with):';
                $parts[] = $encoded;
            }
        }

        /*
         * The application's own source, line-numbered, so the answer can cite
         * a line rather than describe one. This is the section that decides
         * whether the reply is a diagnosis or a suggestion to go and look.
         */
        foreach ($incident->code() as $label => $source) {
            $parts[] = '';
            $parts[] = 'CODE — ' . $label;
            $parts[] = $source;
        }

        $parts[] = '';
        $parts[] = sprintf(
            'In no more than %d words, plain text, no markdown, no preamble: say what '
            . 'happened and the single change that fixes it. Trace the actual value '
            . 'through the code above and quote it. Cite file and line numbers; they '
            . 'are given. Say whether this is a bug in the code or invalid input that '
            . 'should have been rejected earlier. Do not answer with "validate the '
            . 'input", "check the format", or any other instruction to go and '
            . 'investigate — investigating is the job you are doing. Do not ask for '
            . 'anything already shown above. Values shown as %s were scrubbed of '
            . 'secrets deliberately; that is not the bug. Only if the code that would '
            . 'explain this is genuinely absent above, begin with "UNSURE:" and name '
            . 'the one file or value you would need.',
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
