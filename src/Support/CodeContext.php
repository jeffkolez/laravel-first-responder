<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

use ReflectionMethod;
use ReflectionFunction;
use Throwable;

/**
 * The code the model needs in order to stop hedging.
 *
 * Why five lines is not enough
 * ---------------------------
 * A frame's source window shows what threw. It almost never shows what went
 * wrong, because the two are rarely on the same line:
 *
 *     57:  if (! preg_match('/([a-zA-Z]+)(\d+)/', $date, $matches)) { … }
 *     72:  $month = ProfileDates::monthNumber($matches[1]);
 *     74:  throw new \Exception('… is not a month');   <- the frame
 *
 * Give a model line 74 and five lines either side and it cannot see the
 * pattern that produced $matches[1], and it cannot see what monthNumber()
 * considers a month. So it does the only honest thing available to it and
 * says "I would need to inspect the input format" — which is exactly the
 * sentence the alert existed to save somebody from writing.
 *
 * So: the whole enclosing method, plus the bodies of the application's own
 * methods it calls. That is usually all it takes to turn "possible cause"
 * into a cause.
 *
 * Reflection, not parsing
 * -----------------------
 * The enclosing method is already known — Incident records each frame's
 * function as `App\Http\Controllers\EventController->date`, so its file and
 * line span come straight from ReflectionMethod. Callees are resolved the
 * same way, once the short name in the source is expanded through the file's
 * own `use` statements. No PHP parser, no heuristics about braces.
 *
 * What it will not read
 * ---------------------
 * Anything outside base_path, which means vendor is excluded twice over: it
 * would bury the application's code in framework internals the model already
 * knows, and frame data is webhook-influenced, so a path check is the line
 * between "reads your source" and "reads any file on the box".
 */
final class CodeContext
{
    /** Beyond this the model is reading a file, not a function. */
    private const MAX_LINES = 90;

    /** One runaway method must not crowd out the callee that explains it. */
    private const MAX_CHARS_PER_BLOCK = 6000;

    private const MAX_TOTAL_CHARS = 14000;

    public function __construct(
        private readonly string $basePath,
        private readonly bool $enabled = true,
        private readonly int $maxSymbols = 3,
    ) {
    }

    /**
     * Source blocks, labelled, in the order the model should read them.
     *
     * @return array<string, string>
     */
    public function collect(Incident $incident): array
    {
        if (! $this->enabled) {
            return [];
        }

        $frames = $incident->significantFrames(1);

        if ($frames === []) {
            return [];
        }

        $frame = $frames[0];
        $blocks = [];

        $enclosing = $this->safely(fn () => $this->enclosing($frame));

        if ($enclosing !== null) {
            $blocks[$enclosing[0]] = $enclosing[1];
        }

        foreach ($this->callees($frame, $enclosing[1] ?? $frame->context()) as $label => $source) {
            // One enclosing method plus maxSymbols callees.
            if (count($blocks) >= $this->maxSymbols + 1) {
                break;
            }

            $blocks[$label] = $source;
        }

        return $this->withinBudget($blocks);
    }

    /**
     * The method the failing line sits in.
     *
     * Windowed when it is long: a 200-line controller action sent in full is
     * mostly code that had nothing to do with it, and the lines that did are
     * the ones near the failure. The window stays inside the method's own
     * bounds so the model is never shown half of a neighbouring function.
     *
     * @return array{0: string, 1: string}|null  [label, source]
     */
    private function enclosing(Frame $frame): ?array
    {
        $reflection = $this->reflect($frame->function);

        if ($reflection === null) {
            return null;
        }

        $file = (string) $reflection->getFileName();

        if (! $this->readable($file)) {
            return null;
        }

        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        if ($end - $start > self::MAX_LINES) {
            $half = intdiv(self::MAX_LINES, 2);
            $start = max($start, $frame->line - $half);
            $end = min($reflection->getEndLine(), $frame->line + $half);
        }

        $source = $this->slice($file, $start, $end);

        return $source === null
            ? null
            : [$this->label($frame->function, $file, $start, $end), $source];
    }

    /**
     * The application's own methods called near the failure.
     *
     * Searched outward from the failing line, because the call that broke is
     * usually the one just above the throw rather than the first one in the
     * method. Vendor code is skipped by the base_path check in readable():
     * the model already knows what Cache::remember does, and sending it is
     * both noise and money.
     *
     * @return array<string, string>
     */
    private function callees(Frame $frame, string $window): array
    {
        $file = $frame->file;

        if (! $this->readable($file)) {
            return [];
        }

        $aliases = $this->safely(fn () => $this->aliases($file)) ?? [];
        $self = $this->className($frame->function);

        $found = [];

        foreach ($this->orderedLines($window, $frame->line) as $line) {
            preg_match_all(
                '/(?:([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::|\$this\s*->|(?:self|static)\s*::)\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/',
                $line,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $class = ($match[1] ?? '') === '' ? $self : $this->resolve($match[1], $aliases);
                $method = $match[2];

                if ($class === null) {
                    continue;
                }

                $key = $class . '::' . $method;

                if (isset($found[$key])) {
                    continue;
                }

                $block = $this->safely(fn () => $this->method($class, $method));

                if ($block !== null) {
                    $found[$key] = $block;
                }

                if (count($found) >= $this->maxSymbols) {
                    return $this->relabel($found);
                }
            }
        }

        return $this->relabel($found);
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $found
     * @return array<string, string>
     */
    private function relabel(array $found): array
    {
        $out = [];

        foreach ($found as $block) {
            $out[$block[0]] = $block[1];
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function method(string $class, string $method): ?array
    {
        if (! method_exists($class, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($class, $method);
        $file = (string) $reflection->getFileName();

        if (! $this->readable($file)) {
            return null;
        }

        $start = $reflection->getStartLine();
        $end = min($reflection->getEndLine(), $start + self::MAX_LINES);

        $source = $this->slice($file, $start, $end);

        if ($source === null) {
            return null;
        }

        return [
            $this->label($class . '::' . $method, $file, $start, $end),
            $source,
        ];
    }

    /**
     * Lines of the window, nearest the failure first.
     *
     * @return string[]
     */
    private function orderedLines(string $window, int $failingLine): array
    {
        $lines = preg_split('/\R/', $window) ?: [];

        // The window is numbered, so the failing line can be found in it
        // rather than guessed at from an offset.
        $pivot = 0;

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(\d+)\s*\|/', $line, $m) && (int) $m[1] === $failingLine) {
                $pivot = $i;

                break;
            }
        }

        $ordered = [];

        for ($distance = 0; $distance < count($lines); $distance++) {
            foreach ([$pivot - $distance, $pivot + $distance] as $index) {
                if ($index >= 0 && $index < count($lines) && ! isset($ordered[$index])) {
                    $ordered[$index] = $lines[$index];
                }
            }
        }

        return array_values($ordered);
    }

    /**
     * Short class name to fully-qualified, through the file's own imports.
     *
     * @param  array<string, string>  $aliases
     */
    private function resolve(string $name, array $aliases): ?string
    {
        $name = ltrim($name, '\\');

        foreach ([$aliases[$name] ?? null, $name] as $candidate) {
            if ($candidate !== null && $this->safely(fn () => class_exists($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The file's `use` statements, alias to fully-qualified.
     *
     * Read with the tokeniser rather than a regex because `use` is three
     * different statements in PHP — imports, traits and closure bindings —
     * and only one of them is an import.
     *
     * @return array<string, string>
     */
    private function aliases(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $aliases = [];
        $collecting = false;
        $buffer = '';
        $alias = null;
        $expectAlias = false;

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_USE) {
                // A `use` inside a class body is a trait, and inside a
                // function signature a closure binding. Only top-level
                // imports matter here, and the tokeniser reaches those first.
                $collecting = true;
                $buffer = '';
                $alias = null;
                $expectAlias = false;

                continue;
            }

            if (! $collecting) {
                continue;
            }

            if (is_array($token)) {
                if ($token[0] === T_AS) {
                    $expectAlias = true;

                    continue;
                }

                if (in_array($token[0], [T_STRING, T_NS_SEPARATOR], true)
                    || (defined('T_NAME_QUALIFIED') && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true))) {
                    $expectAlias ? $alias = $token[1] : $buffer .= $token[1];
                }

                continue;
            }

            if ($token === ';' || $token === ',') {
                $qualified = ltrim($buffer, '\\');

                if ($qualified !== '') {
                    $short = $alias ?? substr((string) strrchr('\\' . $qualified, '\\'), 1);
                    $aliases[$short] = $qualified;
                }

                $buffer = '';
                $alias = null;
                $expectAlias = false;

                if ($token === ';') {
                    $collecting = false;
                }

                continue;
            }

            if ($token === '(' || $token === '{') {
                // A closure binding or a grouped import: not worth the branch.
                $collecting = false;
            }
        }

        return $aliases;
    }

    private function className(?string $function): ?string
    {
        if ($function === null) {
            return null;
        }

        $class = preg_split('/->|::/', $function)[0] ?? null;

        return $class !== null && $class !== '' && class_exists($class) ? $class : null;
    }

    private function reflect(?string $function): ReflectionMethod|ReflectionFunction|null
    {
        if ($function === null || $function === '' || str_contains($function, '{closure')) {
            return null;
        }

        if (preg_match('/^(.+?)(?:->|::)(.+)$/', $function, $matches)) {
            return method_exists($matches[1], $matches[2])
                ? new ReflectionMethod($matches[1], $matches[2])
                : null;
        }

        return function_exists($function) ? new ReflectionFunction($function) : null;
    }

    /**
     * Numbered, because a diagnosis that says "line 57" is worth more than one
     * that says "the preg_match call", and the model can only cite a number it
     * was given.
     */
    private function slice(string $file, int $start, int $end): ?string
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return null;
        }

        $out = [];

        for ($n = max(1, $start); $n <= min($end, count($lines)); $n++) {
            $out[] = sprintf('%5d| %s', $n, $lines[$n - 1]);
        }

        if ($out === []) {
            return null;
        }

        $source = implode("\n", $out);

        return mb_strlen($source) > self::MAX_CHARS_PER_BLOCK
            ? mb_substr($source, 0, self::MAX_CHARS_PER_BLOCK) . "\n… truncated"
            : $source;
    }

    private function label(string $symbol, string $file, int $start, int $end): string
    {
        return sprintf('%s  [%s:%d-%d]', $symbol, $this->relative($file), $start, $end);
    }

    private function relative(string $file): string
    {
        $base = rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /**
     * Inside the application, and a real file.
     *
     * The same check SourceExtractor makes, and for the same reason: frames
     * can arrive from a webhook, so without it a crafted payload turns an
     * error reporter into an arbitrary file reader.
     */
    private function readable(string $file): bool
    {
        if ($file === '' || ! is_file($file) || ! is_readable($file)) {
            return false;
        }

        $real = realpath($file);
        $base = realpath($this->basePath);

        if ($real === false || $base === false) {
            return false;
        }

        if (! str_starts_with($real, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return false;
        }

        // Vendor is the application's dependencies, not the application. The
        // model knows the framework; what it does not know is this codebase.
        return ! str_contains($real, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
    }

    /**
     * @param  array<string, string>  $blocks
     * @return array<string, string>
     */
    private function withinBudget(array $blocks): array
    {
        $out = [];
        $spent = 0;

        foreach ($blocks as $label => $source) {
            $spent += mb_strlen($source);

            if ($spent > self::MAX_TOTAL_CHARS) {
                break;
            }

            $out[$label] = $source;
        }

        return $out;
    }

    /**
     * @template T
     * @param  callable(): T  $get
     * @return T|null
     */
    private function safely(callable $get): mixed
    {
        try {
            return $get();
        } catch (Throwable) {
            return null;
        }
    }
}
