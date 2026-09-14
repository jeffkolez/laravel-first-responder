<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

/**
 * Renders an incident as the body of a GitHub issue.
 *
 * The audience is two readers with different needs: a person deciding in five
 * seconds whether this matters, and — later — an agent that has nothing but
 * this text to work from. So the location and the diagnosis come first for the
 * human, and the source block is included in full for the machine.
 *
 * Everything rendered here has already been through the Redactor, which runs
 * in FirstResponder::prepare() before serialisation. That ordering is what
 * makes it safe to put source lines in a place that is not the application.
 */
final class IssueBody
{
    /**
     * Ties an issue to a fingerprint so we can find it again without keeping a
     * database. An HTML comment, because GitHub renders it to nothing.
     *
     * Deliberately free of colons. Fingerprints for externally-grouped errors
     * look like "ext:4171", and GitHub's issue search reads a colon as a
     * qualifier separator even inside a quoted phrase — the search would parse
     * "ext" as an unknown qualifier and quietly return nothing. Everything is
     * flattened to [A-Za-z0-9-] so the marker is one plain token.
     */
    private const MARKER_PREFIX = 'fr-fingerprint-';

    /** GitHub truncates past 256; leave room rather than meet the limit exactly. */
    private const MAX_TITLE = 200;

    /** A request block past this is a payload dump, not context. */
    private const MAX_CONTEXT = 4000;

    public static function marker(string $fingerprint): string
    {
        return self::MARKER_PREFIX . preg_replace('/[^A-Za-z0-9-]+/', '-', $fingerprint);
    }

    public static function title(Incident $incident): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $incident->title()) ?? $incident->title());

        if ($title === '') {
            $title = 'Unhandled error';
        }

        return mb_strlen($title) > self::MAX_TITLE
            ? mb_substr($title, 0, self::MAX_TITLE - 1) . '…'
            : $title;
    }

    public static function render(Incident $incident, ?Diagnosis $diagnosis, string $fingerprint): string
    {
        $sections = [
            '**' . self::escape($incident->title()) . '**',
            self::facts($incident),
        ];

        if ($diagnosis !== null && ! $diagnosis->isEmpty()) {
            $sections[] = ($diagnosis->confident
                    ? '### Likely cause'
                    : '### Possible cause (low confidence)')
                . "\n\n" . $diagnosis->summary;
        }

        if ($source = self::source($incident)) {
            $sections[] = $source;
        }

        if ($context = self::context($incident)) {
            $sections[] = $context;
        }

        foreach (self::code($incident) as $block) {
            $sections[] = $block;
        }

        if (self::isUsableUrl($incident->externalUrl)) {
            $sections[] = '[View in the error tracker](' . $incident->externalUrl . ')';
        }

        $sections[] = '<!-- ' . self::marker($fingerprint) . ' -->';

        return implode("\n\n", array_filter($sections, static fn ($s) => trim((string) $s) !== ''));
    }

    private static function facts(Incident $incident): string
    {
        $parts = [];

        $frames = $incident->significantFrames(1);

        if ($frames !== []) {
            $parts[] = '`' . $frames[0]->location() . '`';

            if ($signature = $frames[0]->signature()) {
                $parts[] = '`' . self::escape($signature) . '`';
            }
        }

        if (($incident->environment ?? '') !== '') {
            $parts[] = (string) $incident->environment;
        }

        if (($incident->release ?? '') !== '') {
            $parts[] = 'release `' . $incident->release . '`';
        }

        $lines = $parts === [] ? [] : [implode(' · ', $parts)];

        if (($incident->url ?? '') !== '') {
            $method = $incident->context['method'] ?? null;

            $lines[] = 'Request: `' . trim(((string) $method) . ' ' . $incident->url) . '`';
        }

        return implode("\n", $lines);
    }

    /**
     * Everything the request was carrying, as a table.
     *
     * The chat alert shows the few values most likely to be the bug. This
     * shows all of them, because the two readers are different: chat is read
     * by someone deciding whether to care, the issue by whoever — or whatever
     * — sits down to fix it, and that reader wants the user agent, the
     * referer and the IP that the alert correctly left out.
     *
     * JSON in a fence, not a markdown table: values here are arbitrary strings
     * from a request, and a pipe character in one of them would shred a table.
     */
    private static function context(Incident $incident): ?string
    {
        $context = $incident->facts();

        if ($context === []) {
            return null;
        }

        $encoded = json_encode(
            $context,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (! is_string($encoded)) {
            return null;
        }

        if (mb_strlen($encoded) > self::MAX_CONTEXT) {
            $encoded = mb_substr($encoded, 0, self::MAX_CONTEXT) . "\n… truncated";
        }

        $fence = str_repeat('`', max(3, self::longestBacktickRun($encoded) + 1));

        return "### Request\n\n" . $fence . "json\n" . $encoded . "\n" . $fence;
    }

    /**
     * The source around the failing line, fenced.
     *
     * The fence length is computed rather than fixed at three backticks. Source
     * containing a markdown fence in a heredoc or a docblock would otherwise
     * close the block early and spill the rest of the file into the issue as
     * prose — rare, ugly, and confusing when it happens.
     */
    private static function source(Incident $incident): ?string
    {
        $frames = $incident->significantFrames(1);

        if ($frames === []) {
            return null;
        }

        $context = $frames[0]->context();

        if (trim($context) === '') {
            return null;
        }

        $fence = str_repeat('`', max(3, self::longestBacktickRun($context) + 1));

        return "### Source\n\n"
            . $fence . self::languageOf($frames[0]->file) . "\n"
            . $context . "\n"
            . $fence;
    }

    /**
     * The application source the diagnosis was made against.
     *
     * Worth the length here in a way it is not in chat. Whoever picks this up
     * — a person or an agent — needs to see what the model saw, both to check
     * the diagnosis and to make the change without opening the repository.
     *
     * @return string[]
     */
    private static function code(Incident $incident): array
    {
        $blocks = [];

        foreach ($incident->code() as $label => $source) {
            $fence = str_repeat('`', max(3, self::longestBacktickRun($source) + 1));

            $blocks[] = '### ' . self::escape($label) . "\n\n"
                . $fence . "php\n" . $source . "\n" . $fence;
        }

        return $blocks;
    }

    private static function longestBacktickRun(string $text): int
    {
        preg_match_all('/`+/', $text, $matches);

        $lengths = array_map('strlen', $matches[0] ?? []);

        return $lengths === [] ? 0 : max($lengths);
    }

    private static function languageOf(string $file): string
    {
        $extension = strtolower(pathinfo(
            (string) preg_replace('/[?#].*$/', '', $file),
            PATHINFO_EXTENSION
        ));

        return match ($extension) {
            'php' => 'php',
            'ts' => 'ts',
            'tsx' => 'tsx',
            'js', 'mjs', 'cjs' => 'js',
            'jsx' => 'jsx',
            'py' => 'python',
            'rb' => 'ruby',
            'go' => 'go',
            'vue' => 'vue',
            default => '',
        };
    }

    /**
     * Only http(s). The external URL comes from a webhook payload, so it is
     * attacker-influenced, and a javascript: or data: URL rendered as a link in
     * a repository everyone on the team clicks through is not a risk worth
     * taking for a convenience link.
     */
    private static function isUsableUrl(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /** Stop an exception message that contains markdown from restyling the issue. */
    private static function escape(string $text): string
    {
        return str_replace(['*', '_', '`'], ['\*', '\_', '\`'], $text);
    }
}
