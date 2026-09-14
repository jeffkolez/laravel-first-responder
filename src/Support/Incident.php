<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

use Throwable;

/**
 * Everything the package knows about one thing going wrong.
 *
 * This is the seam of the design. A native Laravel exception and a Sentry
 * webhook payload arrive looking nothing alike; both become an Incident, and
 * every stage after that (redaction, diagnosis, notification) is written
 * against this one shape. Adding a third source later means writing one
 * normaliser and touching nothing else.
 *
 * Serialisable to and from an array because it crosses a queue boundary, and
 * queueing a raw Throwable is a well-known way to produce unserializable jobs.
 */
final class Incident
{
    /** A string argument longer than this is a payload, not a clue. */
    private const MAX_ARG_LENGTH = 120;

    /** Past this many arguments the signature stops being readable. */
    private const MAX_ARGS = 8;

    /** Exception chains are usually 2 deep; 5 is room to spare, not a budget. */
    private const MAX_PREVIOUS = 5;

    /**
     * @param  Frame[]                $frames
     * @param  array<string, mixed>   $context  Arbitrary extra (request data, user, tags).
     */
    public function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly array $frames = [],
        public readonly ?string $url = null,
        public readonly ?string $environment = null,
        public readonly ?string $release = null,
        public readonly string $level = 'error',
        public readonly array $context = [],
        public readonly ?string $externalId = null,
        public readonly ?string $externalUrl = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function fromThrowable(Throwable $e, array $context = []): self
    {
        $trace = array_values($e->getTrace());

        /*
         * PHP's trace is offset by one from what a reader expects, and getting
         * this wrong is why most stack traces are harder to read than they
         * need to be.
         *
         * getFile()/getLine() is where the exception was constructed. Entry 0
         * of the trace is not that line — it is the call that got us into the
         * function containing that line, so its file/line point at the CALLER
         * while its function/args describe the code that actually threw.
         *
         * So the two halves are recombined: each frame takes its location from
         * one entry and its function and arguments from the next. The result
         * reads the way people already think stack traces read — "at this
         * line, inside this call, with these values" — and, crucially, it puts
         * the arguments on the line they belong to.
         */
        $frames = [new Frame(
            $e->getFile(),
            $e->getLine(),
            self::functionName($trace[0] ?? null),
            true,
            [],
            null,
            [],
            self::renderArgs($trace[0]['args'] ?? null),
        )];

        foreach ($trace as $i => $t) {
            if (! isset($t['file'])) {
                // Internal/closure frames with no file are not actionable and
                // just crowd out the ones that are.
                continue;
            }

            $enclosing = $trace[$i + 1] ?? null;

            $frames[] = new Frame(
                (string) $t['file'],
                (int) ($t['line'] ?? 0),
                self::functionName($enclosing),
                true,
                [],
                null,
                [],
                self::renderArgs($enclosing['args'] ?? null),
            );
        }

        $previous = self::chain($e);

        if ($previous !== []) {
            // Never clobber what the caller passed; theirs is deliberate.
            $context += ['previous' => $previous];
        }

        return new self(
            $e::class,
            $e->getMessage(),
            $frames,
            null,
            null,
            null,
            'error',
            $context,
        );
    }

    /**
     * The wrapped exceptions, outermost first.
     *
     * A wrapper usually says what failed and the innermost one says why: "SQL
     * error" wrapping "Connection refused" wrapping "no route to host" is three
     * useful sentences, and reporting only the first is reporting the least
     * informative of them.
     *
     * Kept in context rather than spliced into the frames, because the
     * fingerprint is built from the innermost in-app frame and rearranging
     * frames would silently re-group every chained error in the system.
     *
     * @return string[]
     */
    private static function chain(Throwable $e): array
    {
        $out = [];
        $previous = $e->getPrevious();

        while ($previous !== null && count($out) < self::MAX_PREVIOUS) {
            $out[] = sprintf(
                '%s: %s at %s:%d',
                $previous::class,
                $previous->getMessage(),
                $previous->getFile(),
                $previous->getLine(),
            );

            $previous = $previous->getPrevious();
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $entry
     */
    private static function functionName(?array $entry): ?string
    {
        if ($entry === null || ! isset($entry['function'])) {
            return null;
        }

        $function = (string) $entry['function'];

        /*
         * PHP 8.4 names a closure after where it was declared:
         * "{closure:App\Providers\RouteServiceProvider::boot():42}". In a
         * routes file that is a hundred characters of path repeating the frame
         * directly above it, and it pushes the arguments — the part worth
         * reading — off the end of a phone screen.
         */
        if (str_starts_with($function, '{closure')) {
            $function = '{closure}';
        }

        if (! isset($entry['class'])) {
            return $function;
        }

        return $entry['class'] . ($entry['type'] ?? '::') . $function;
    }

    /**
     * Call arguments, rendered for display.
     *
     * Usually absent. php.ini-production sets `zend.exception_ignore_args=On`,
     * which strips arguments from every trace PHP produces — so this is a
     * bonus when it is there, never something the rest of the package leans on.
     * The URL and route parameters captured by RequestContext are the reliable
     * answer to "with what data"; this is the one that occasionally answers it
     * better.
     *
     * @return string[]
     */
    private static function renderArgs(mixed $args): array
    {
        if (! is_array($args)) {
            return [];
        }

        return array_map(
            static fn ($a) => self::renderValue($a),
            array_slice(array_values($args), 0, self::MAX_ARGS)
        );
    }

    /**
     * One value, as a short string a person can read.
     *
     * Objects become their class name and nothing else. An Eloquent model
     * printed in full is every column of a row — which is how a package like
     * this ends up posting somebody's address into a chat channel.
     */
    public static function renderValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => self::quote($value),
            is_array($value) => 'array(' . count($value) . ')',
            is_object($value) => $value::class,
            default => gettype($value),
        };
    }

    private static function quote(string $value): string
    {
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        if (mb_strlen($value) > self::MAX_ARG_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_ARG_LENGTH - 1) . '…';
        }

        return "'" . $value . "'";
    }

    /** Short human title, e.g. "RuntimeException: Connection refused". */
    public function title(): string
    {
        $message = trim($this->message);

        return $message === '' ? $this->type : "{$this->type}: {$message}";
    }

    /**
     * The frames worth showing, innermost first.
     *
     * Two things happen here:
     *
     *  - vendor/framework frames are dropped, because "the error happened in
     *    Illuminate\Routing\Router" is true of almost every Laravel exception
     *    and tells nobody anything;
     *  - what remains is capped, because past three frames nobody is reading
     *    and every extra frame is tokens on the diagnosis bill.
     *
     * If filtering leaves nothing (a crash entirely inside vendor code) the
     * unfiltered frames are returned instead. A less useful report beats a
     * report with no location in it at all.
     *
     * @return Frame[]
     */
    public function significantFrames(int $limit = 3): array
    {
        $inApp = array_values(array_filter($this->frames, static fn (Frame $f) => $f->inApp));

        $frames = $inApp === [] ? $this->frames : $inApp;

        return array_slice($frames, 0, max(1, $limit));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            'frames' => array_map(static fn (Frame $f) => $f->toArray(), $this->frames),
            'url' => $this->url,
            'environment' => $this->environment,
            'release' => $this->release,
            'level' => $this->level,
            'context' => $this->context,
            'external_id' => $this->externalId,
            'external_url' => $this->externalUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['type'] ?? 'Error'),
            (string) ($data['message'] ?? ''),
            array_map(
                static fn (array $f) => Frame::fromArray($f),
                (array) ($data['frames'] ?? [])
            ),
            $data['url'] ?? null,
            $data['environment'] ?? null,
            $data['release'] ?? null,
            (string) ($data['level'] ?? 'error'),
            (array) ($data['context'] ?? []),
            $data['external_id'] ?? null,
            $data['external_url'] ?? null,
        );
    }

    /**
     * A copy with request details filled in where this incident has none.
     *
     * Fill, not overwrite. An incident that arrived from Sentry already carries
     * the URL of the request that broke, and the current request is the
     * webhook delivering the news about it — clobbering the former with the
     * latter would replace the fact with an irrelevance.
     *
     * @param  array<string, mixed>  $context
     */
    public function withRequest(?string $url, array $context): self
    {
        return new self(
            $this->type,
            $this->message,
            $this->frames,
            $this->url ?? $url,
            $this->environment,
            $this->release,
            $this->level,
            $this->context + $context,
            $this->externalId,
            $this->externalUrl,
        );
    }
}
