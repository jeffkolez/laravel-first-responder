<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

/**
 * Decides which repository an incident belongs to.
 *
 * Most applications are one repo and never touch this. It exists for the split
 * ones: a Laravel API and a JavaScript frontend reporting into the same error
 * tracker, where roughly half the incidents belong to a codebase the PHP
 * process has never seen. Filing those against the API repo makes the whole
 * feature feel broken.
 *
 * The signal used is the file extension of the innermost in-app frame, because
 * it is the only thing available that is both cheap and reliable. Anything
 * cleverer (parsing paths, matching against a manifest) needs configuration
 * that would be wrong the first time someone renames a directory.
 */
final class RepoRouter
{
    /** @var array<string, string> extension => repo */
    private array $byExtension = [];

    /**
     * @param  array<string, string>  $map  "ts,tsx,js" => "owner/frontend"
     */
    public function __construct(
        private readonly ?string $default = null,
        array $map = [],
    ) {
        foreach ($map as $extensions => $repo) {
            if (! is_string($repo) || trim($repo) === '') {
                continue;
            }

            foreach (explode(',', (string) $extensions) as $extension) {
                $extension = strtolower(trim($extension, " \t."));

                if ($extension !== '') {
                    $this->byExtension[$extension] = trim($repo);
                }
            }
        }
    }

    /**
     * @param  string|null  $default  Overrides the configured default, so a
     *                                notification route can supply one per-send.
     */
    public function repoFor(Incident $incident, ?string $default = null): ?string
    {
        $frames = $incident->significantFrames(1);

        if ($frames !== []) {
            $extension = self::extensionOf($frames[0]->file);

            if ($extension !== '' && isset($this->byExtension[$extension])) {
                return $this->byExtension[$extension];
            }
        }

        return self::blankToNull($default) ?? self::blankToNull($this->default);
    }

    /**
     * Frontend frames often arrive as URLs rather than paths — a bundle served
     * with a cache-busting query string, for instance — so anything after ? or
     * # is dropped before the extension is read.
     */
    private static function extensionOf(string $file): string
    {
        $file = (string) preg_replace('/[?#].*$/', '', $file);

        return strtolower(pathinfo($file, PATHINFO_EXTENSION));
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
