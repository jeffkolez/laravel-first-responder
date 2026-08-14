<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

/**
 * One line of a stack trace, normalised.
 *
 * Deliberately a plain value object rather than an array: every source this
 * package accepts (a native Throwable, a Sentry webhook, whatever comes next)
 * produces frames in a slightly different shape, and normalising at the edge
 * means the rest of the package only ever reasons about one of them.
 */
final class Frame
{
    /**
     * @param  string[]  $preContext   Source lines immediately before $line.
     * @param  string[]  $postContext  Source lines immediately after $line.
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly ?string $function = null,
        public readonly bool $inApp = true,
        public readonly array $preContext = [],
        public readonly ?string $contextLine = null,
        public readonly array $postContext = [],
    ) {
    }

    /**
     * Source around this frame, if any travelled with it.
     *
     * Returns '' rather than null when there is nothing, so callers can treat
     * "no source" and "empty source" identically — there is no useful
     * distinction and an extra null check at every call site is just noise.
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
        );
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
        ];
    }
}
