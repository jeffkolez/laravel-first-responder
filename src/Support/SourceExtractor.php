<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

/**
 * Fills in source context for frames that arrived without any.
 *
 * A native Throwable carries file and line but no surrounding code, and the
 * surrounding code is most of what makes a diagnosis worth reading. Sentry
 * captures context at throw time and ships it in the payload, so this only runs
 * where it is needed, and it never overwrites context that already exists.
 * Overwriting would be wrong as well as wasteful: Sentry's snapshot is of the
 * code that ran, and the file on disk may have been redeployed since.
 *
 * Reads are confined to the project root. Frame data can originate from a
 * webhook, which makes the file path externally influenced. Without the
 * base-path check, an attacker who could forge a frame could read any file the
 * PHP user can, and then have it mailed to a chat channel. Every path is
 * resolved with realpath() and rejected unless it sits inside the configured
 * root.
 */
final class SourceExtractor
{
    private ?string $root;

    public function __construct(
        string $basePath,
        private readonly int $linesAround = 5,
    ) {
        $resolved = realpath($basePath);

        $this->root = $resolved === false ? null : $resolved;
    }

    /**
     * Return a copy of the frame with source context attached where possible.
     */
    public function fill(Frame $frame): Frame
    {
        // A native Throwable has no idea what "your code" means: PHP's trace is
        // just files, so Incident::fromThrowable marks every frame in-app and a
        // report would lead with whichever framework file happened to throw.
        // This object is the only one that knows where the project root is, so
        // it is the only one that can tell the difference.
        //
        // It only ever downgrades. Sentry sends real in_app flags of its own and
        // a frame it has already called foreign must not be promoted back.
        $inApp = $frame->inApp && ! $this->isVendor($frame->file);

        $pre = $frame->preContext;
        $line = $frame->contextLine;
        $post = $frame->postContext;

        if ($frame->context() === '' && ($lines = $this->read($frame->file, $frame->line)) !== null) {
            [$pre, $line, $post] = $lines;
        }

        if ($inApp === $frame->inApp && $line === $frame->contextLine && $pre === $frame->preContext) {
            return $frame;
        }

        return new Frame(
            $frame->file,
            $frame->line,
            $frame->function,
            $inApp,
            $pre,
            $line,
            $post,
        );
    }

    /**
     * Is this frame a dependency rather than the application?
     *
     * Inside the project root the question is exact: it is a dependency if it
     * sits under vendor/. Outside the root — PHP internals, an eval'd tinker
     * line, a JavaScript bundle reported through Sentry — there is no root to
     * measure against, so fall back to looking for the path segment.
     */
    private function isVendor(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $normalised = str_replace('\\', '/', $path);

        if ($this->root !== null) {
            $root = str_replace('\\', '/', $this->root);

            if (str_starts_with($normalised, $root . '/')) {
                return str_starts_with($normalised, $root . '/vendor/');
            }
        }

        // Slashes on both sides, so a project living at /srv/vendor-app is not
        // mistaken for a dependency.
        return str_contains($normalised, '/vendor/');
    }

    /**
     * @param  Frame[]  $frames
     * @return Frame[]
     */
    public function fillAll(array $frames): array
    {
        return array_map(fn (Frame $f) => $this->fill($f), $frames);
    }

    /**
     * @return array{0: string[], 1: string|null, 2: string[]}|null
     */
    private function read(string $path, int $line): ?array
    {
        if ($this->root === null || $path === '' || $line < 1) {
            return null;
        }

        $real = realpath($path);

        // realpath() resolves symlinks and ../ segments, so this comparison
        // cannot be walked out of. The separator guards against /app matching
        // a sibling directory like /app-secrets.
        if ($real === false || ! str_starts_with($real, $this->root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (! is_file($real) || ! is_readable($real)) {
            return null;
        }

        $contents = @file($real, FILE_IGNORE_NEW_LINES);

        if ($contents === false) {
            return null;
        }

        $index = $line - 1;

        if (! array_key_exists($index, $contents)) {
            return null;
        }

        $start = max(0, $index - $this->linesAround);

        return [
            array_values(array_slice($contents, $start, $index - $start)),
            $contents[$index],
            array_values(array_slice($contents, $index + 1, $this->linesAround)),
        ];
    }
}
