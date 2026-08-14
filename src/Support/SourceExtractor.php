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
        if ($frame->context() !== '') {
            return $frame;
        }

        $lines = $this->read($frame->file, $frame->line);

        if ($lines === null) {
            return $frame;
        }

        [$pre, $line, $post] = $lines;

        return new Frame(
            $frame->file,
            $frame->line,
            $frame->function,
            $frame->inApp,
            $pre,
            $line,
            $post,
        );
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
