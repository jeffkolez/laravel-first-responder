<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

/**
 * Strips secrets out of anything before it leaves the building.
 *
 * Why this class matters
 * ----------------------
 * Diagnosing an error usefully means sending real source code and real request
 * data to a third-party model. That is a new egress path out of a production
 * app, and "we send your code to OpenAI" is the reason a sensible team says no
 * to a package like this.
 *
 * So redaction is the default, not a feature flag, and it runs on every string
 * that goes anywhere near a diagnostician.
 *
 * Three layers, cheapest and most reliable first:
 *
 *  1. Exact environment values. The strongest signal available, and the one
 *     pattern-matching cannot replicate. If APP_KEY or DB_PASSWORD or some
 *     bespoke SECRET_SAUCE_TOKEN is sitting in the text, we know its exact
 *     value and need no regex. This catches secrets that look like nothing in
 *     particular, which is most of them.
 *
 *  2. Known token shapes. Provider-issued credentials with recognisable
 *     prefixes (sk-, ghp_, AKIA, xox…), JWTs, PEM blocks. Catches secrets that
 *     are in the code but not in the environment: a hardcoded key someone
 *     committed, or a token pulled from a database.
 *
 *  3. Assignment shapes. `'password' => '...'`, `$token = "..."`, `SECRET=...`.
 *     The catch-all for the ones the first two missed.
 *
 * Biased toward over-redaction. A diagnosis slightly worse because a string got
 * masked is a small cost. A leaked production credential is not.
 */
final class Redactor
{
    public const MASK = '[redacted]';

    /**
     * Environment values shorter than this are never matched.
     *
     * Without a floor, APP_ENV=local turns every occurrence of the word "local"
     * anywhere in your source into [redacted], including the lines the model
     * needs to read. Real secrets are long, so this floor costs nothing.
     */
    private const MIN_ENV_VALUE_LENGTH = 8;

    /** Env values that are common words are skipped even if long enough. */
    private const ENV_VALUE_DENYLIST = [
        'localhost', 'true', 'false', 'null', 'production', 'development',
        'staging', 'testing', 'database', 'redis', 'memcached', 'sync',
        'array', 'file', 'daily', 'single', 'stack', 'public', 'private',
    ];

    /**
     * Substrings that mark an environment variable's name as sensitive.
     *
     * Used only to decide whether an env value is worth masking. Kept broad: a
     * false positive here means one extra harmless mask.
     */
    private const SENSITIVE_KEY_HINTS = [
        'KEY', 'SECRET', 'TOKEN', 'PASSWORD', 'PASSWD', 'PWD', 'AUTH',
        'CREDENTIAL', 'PRIVATE', 'SALT', 'CERT', 'SIGNATURE', 'DSN', 'HASH',
        'ACCESS', 'SESSION', 'LICENSE', 'WEBHOOK',
    ];

    /** @var array<int, array{0: string, 1: string}> [pattern, replacement] pairs. */
    private array $patterns;

    /** @var string[] Literal secret values, longest first. */
    private array $literals;

    /**
     * @param  string[]  $extraPatterns  Additional regexes (delimiters included).
     *                                   Whole match is masked; use a capture
     *                                   group and it is preserved via $1.
     * @param  string[]  $extraLiterals  Additional exact strings to mask.
     * @param  array<string, string>|null  $environment  Defaults to the real environment.
     */
    public function __construct(
        array $extraPatterns = [],
        array $extraLiterals = [],
        ?array $environment = null,
        private readonly bool $redactEmails = true,
    ) {
        $extra = array_map(
            static fn (string $p) => [$p, self::MASK],
            array_values($extraPatterns)
        );

        $this->patterns = array_merge($this->defaultPatterns(), $extra);
        $this->literals = $this->collectLiterals($environment, $extraLiterals);
    }

    /**
     * Mask secrets in a single string.
     */
    public function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        // Literals first. They are exact, so doing them before the regexes
        // avoids a pattern half-mangling a value and defeating the exact match.
        foreach ($this->literals as $literal) {
            $text = str_replace($literal, self::MASK, $text);
        }

        foreach ($this->patterns as [$pattern, $replacement]) {
            $replaced = @preg_replace($pattern, $replacement, $text);

            // A malformed custom pattern returns null. Keep the last good text
            // instead of blanking it. Losing the content is worse than skipping
            // one rule, and the rule is the user's own.
            if ($replaced !== null) {
                $text = $replaced;
            }
        }

        if ($this->redactEmails) {
            $text = (string) preg_replace(
                '/[\w.+-]+@[\w-]+\.[\w.-]{2,}/',
                self::MASK,
                $text
            );
        }

        return $text;
    }

    /**
     * Recursively mask an array's string values (and its keys' values).
     *
     * @param  array<mixed, mixed>  $data
     * @return array<mixed, mixed>
     */
    public function redactArray(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->keyLooksSensitive($key)) {
                // The key alone is enough. Its value never needs to be seen,
                // whatever shape it happens to have.
                $out[$key] = self::MASK;

                continue;
            }

            $out[$key] = match (true) {
                is_array($value) => $this->redactArray($value),
                is_string($value) => $this->redact($value),
                default => $value,
            };
        }

        return $out;
    }

    /** Mask every frame's source context and file path in place. */
    public function redactIncident(Incident $incident): Incident
    {
        $frames = array_map(function (Frame $f) {
            return new Frame(
                $f->file,
                $f->line,
                $f->function,
                $f->inApp,
                array_map(fn ($l) => $this->redact((string) $l), $f->preContext),
                $f->contextLine === null ? null : $this->redact($f->contextLine),
                array_map(fn ($l) => $this->redact((string) $l), $f->postContext),
                // Arguments are the likeliest place in a frame for a real
                // secret to appear verbatim: a token passed to a client, a
                // password passed to a hasher. They get the same treatment as
                // source, and for a better reason.
                array_map(fn ($a) => $this->redact((string) $a), $f->args),
            );
        }, $incident->frames);

        return new Incident(
            $incident->type,
            $this->redact($incident->message),
            $frames,
            $incident->url === null ? null : $this->redact($incident->url),
            $incident->environment,
            $incident->release,
            $incident->level,
            $this->redactArray($incident->context),
            $incident->externalId,
            $incident->externalUrl,
        );
    }

    public function keyLooksSensitive(string $key): bool
    {
        $upper = strtoupper($key);

        foreach (self::SENSITIVE_KEY_HINTS as $hint) {
            if (str_contains($upper, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>|null  $environment
     * @param  string[]  $extra
     * @return string[]
     */
    private function collectLiterals(?array $environment, array $extra): array
    {
        $env = $environment ?? $_ENV;
        $literals = $extra;

        foreach ($env as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $value = (string) $value;

            if (! $this->keyLooksSensitive($key)) {
                continue;
            }

            if (mb_strlen($value) < self::MIN_ENV_VALUE_LENGTH) {
                continue;
            }

            if (in_array(strtolower($value), self::ENV_VALUE_DENYLIST, true)) {
                continue;
            }

            $literals[] = $value;
        }

        $literals = array_values(array_unique(array_filter($literals, static fn ($l) => $l !== '')));

        // Longest first: masking a short value that is a substring of a longer
        // one would leave the longer secret partially intact and unmatchable.
        usort($literals, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $literals;
    }

    /**
     * Ordered [pattern, replacement] pairs.
     *
     * Where a rule captures the part that identifies what was masked (the
     * variable name, the header scheme, the URL user) that group is preserved.
     * A diagnosis is much better with "password => [redacted]" than with a bare
     * "[redacted]": the model still learns a password is involved, without
     * learning what it is.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function defaultPatterns(): array
    {
        $m = self::MASK;

        return [
            // PEM private key blocks, body included.
            ['/-----BEGIN[A-Z ]*PRIVATE KEY-----.*?-----END[A-Z ]*PRIVATE KEY-----/s', $m],

            // Provider-issued tokens with distinctive prefixes. Anthropic's
            // sk-ant- must precede OpenAI's sk-, or the looser rule wins first
            // and leaves "ant-" dangling in the output.
            ['/\bsk-ant-[A-Za-z0-9_\-]{16,}/', $m],
            ['/\bsk-[A-Za-z0-9_\-]{16,}/', $m],
            ['/\bgithub_pat_[A-Za-z0-9_]{20,}/', $m],
            ['/\bgh[pousr]_[A-Za-z0-9]{16,}/', $m],
            ['/\bxox[baprs]-[A-Za-z0-9-]{10,}/', $m],
            ['/\bAKIA[0-9A-Z]{16}\b/', $m],
            ['/\bAIza[0-9A-Za-z_\-]{35}\b/', $m],
            ['/\bsntrys_[A-Za-z0-9_\-]{16,}/', $m],
            ['/\bbase64:[A-Za-z0-9+\/=]{20,}/', $m],
            ['/\b\d{6,}:[A-Za-z0-9_\-]{30,}/', $m],

            // JSON Web Tokens.
            ['/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', $m],

            // Authorization headers. Keep the scheme.
            ['/\b(Bearer|Basic|Token)\s+[A-Za-z0-9_\-.=+\/]{12,}/i', '$1 ' . $m],

            // Credentials in a URL. Keep scheme://user, drop the password.
            ['/([a-z][a-z0-9+.\-]*:\/\/[^\s:\/@]+):[^\s@\/]+@/i', '$1:' . $m . '@'],

            // 'password' => '…' / $token = "…". Keep the name and the operator.
            [
                '/([\'"]?\w*(?:key|secret|token|password|passwd|pwd|auth|credential)\w*[\'"]?\s*(?:=>|=|:)\s*)[\'"][^\'"\n]{4,}[\'"]/i',
                '$1\'' . $m . '\'',
            ],

            // SECRET=… in dotenv/shell form. Keep the name.
            ['/\b(\w*(?:KEY|SECRET|TOKEN|PASSWORD|PASSWD|PWD|AUTH)\w*)=\S{4,}/', '$1=' . $m],
        ];
    }
}
