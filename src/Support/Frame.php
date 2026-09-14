<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

/**
 * One line of a stack trace, normalised.
 *
 * A plain value object, not an array: every source this package accepts (a
 * native Throwable, a Sentry webhook, whatever comes next) produces frames in a
 * slightly different shape, and normalising at the edge means the rest of the
 * package only ever reasons about one of them.
 */
final class Frame
{
    /**
     * @param  string[]  $preContext   Source lines immediately before $line.
     * @param  string[]  $postContext  Source lines immediately after $line.
     * @param  string[]  $args         Already-rendered call arguments or locals,
     *                                 e.g. ["'banana'", '19'] — see Incident.
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly ?string $function = null,
        public readonly bool $inApp = true,
        public readonly array $preContext = [],
        public readonly ?string $contextLine = null,
        public readonly array $postContext = [],
        public readonly array $args = [],
    ) {
    }

    /**
     * Source around this frame, if any travelled with it.
     *
     * Returns '' when there is nothing, so callers can treat "no source" and
     * "empty source" identically. There is no useful distinction between them,
     * and an extra null check at every call site is noise.
     */
    public function context(): string
    {
        $lines = array_merge(
            $this->preContext,
            $this->contextLine === null ? [] : [$this->contextLine],
            $this->postContext,
        );

        $lines = array_values(array_filter($lines, static fn ($l) => $l !== null));

        return $lines === [] ? '' : implode("\n", $lines);
    }

    public function location(): string
    {
        return $this->line > 0 ? "{$this->file}:{$this->line}" : $this->file;
    }

    /**
     * The call, with its arguments, as you would write it.
     *
     * `ProfileDates::monthNumber('banana')` answers the question a bare
     * function name leaves open. Returns null rather than an empty string when
     * there is no function, so callers can skip the line entirely.
     *
     * Arguments are frequently unavailable: php.ini-production ships
     * `zend.exception_ignore_args=On`, which strips them from every stack trace
     * before PHP hands it over. When that is the case this degrades to
     * `monthNumber()`, which is still better than nothing.
     */
    public function signature(): ?string
    {
        if ($this->function === null || $this->function === '') {
            return null;
        }

        return $this->function . '(' . implode(', ', $this->args) . ')';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['file'] ?? $data['filename'] ?? $data['abs_path'] ?? ''),
            (int) ($data['line'] ?? $data['lineno'] ?? 0),
            isset($data['function']) ? (string) $data['function'] : null,
            (bool) ($data['in_app'] ?? $data['inApp'] ?? true),
            array_values((array) ($data['pre_context'] ?? $data['preContext'] ?? [])),
            isset($data['context_line']) ? (string) $data['context_line'] : ($data['contextLine'] ?? null),
            array_values((array) ($data['post_context'] ?? $data['postContext'] ?? [])),
            self::readArgs($data),
        );
    }

    /**
     * Arguments as this package stores them, or as Sentry sends them.
     *
     * Sentry puts the frame's local variables in `vars`, keyed by name — which
     * is strictly more useful than positional arguments, so it is worth the
     * extra branch. `$date='banana19'` names the variable the source line is
     * about.
     *
     * @param  array<string, mixed>  $data
     * @return string[]
     */
    private static function readArgs(array $data): array
    {
        $args = $data['args'] ?? null;

        if (is_array($args)) {
            return array_values(array_map('strval', array_filter($args, 'is_scalar')));
        }

        $vars = $data['vars'] ?? null;

        if (! is_array($vars)) {
            return [];
        }

        $out = [];

        foreach ($vars as $name => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $out[] = $name . '=' . Incident::renderValue($value);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'function' => $this->function,
            'in_app' => $this->inApp,
            'pre_context' => $this->preContext,
            'context_line' => $this->contextLine,
            'post_context' => $this->postContext,
            'args' => $this->args,
        ];
    }
}
