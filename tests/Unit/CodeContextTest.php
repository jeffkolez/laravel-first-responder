<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Unit;

use JeffKolez\FirstResponder\Support\CodeContext;
use JeffKolez\FirstResponder\Support\Frame;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Tests\Fixtures\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Whether the model is given enough to answer.
 *
 * The failure this guards against is not a crash — it is a diagnosis that
 * reads "verify the input format before passing it to monthNumber()", which
 * is what a model says when it has been shown a throw and nothing that
 * explains it. Every assertion here is a piece of the causal chain that has
 * to be in the prompt for the answer to be a conclusion rather than a
 * suggestion to go and look.
 */
class CodeContextTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../Fixtures/Parser.php';

    /** The line the value came from, seventeen lines above the line that threw. */
    public function test_it_includes_the_whole_enclosing_method_not_just_the_failing_line(): void
    {
        $code = $this->collect($this->throwingLine());

        $enclosing = implode("\n", array_slice($code, 0, 1));

        $this->assertStringContainsString('preg_match', $enclosing);
        $this->assertStringContainsString('not a month', $enclosing);
    }

    /** The method that returned null lives in another file entirely. */
    public function test_it_follows_a_call_into_another_file(): void
    {
        $labels = implode(' ', array_keys($this->collect($this->throwingLine())));

        $this->assertStringContainsString('Months::number', $labels);
    }

    /**
     * Line numbers, so the answer can cite one.
     *
     * A diagnosis saying "line 19" is checkable. One saying "the preg_match
     * call" sends the reader back to the file to find it.
     */
    public function test_source_is_line_numbered(): void
    {
        $code = $this->collect($this->throwingLine());

        $this->assertMatchesRegularExpression('/^\s*\d+\|/m', reset($code));
    }

    /** Methods the failing path never touches are not worth the tokens. */
    public function test_it_does_not_pull_in_unrelated_methods_of_the_same_class(): void
    {
        $labels = implode(' ', array_keys($this->collect($this->throwingLine())));

        $this->assertStringNotContainsString('::unused', $labels);
    }

    /**
     * Frames are webhook-influenced, so a path check is the line between
     * "reads your source" and "reads any file on the box".
     */
    public function test_it_refuses_to_read_outside_the_application(): void
    {
        $incident = new Incident('RuntimeException', 'x', [
            new Frame('/etc/passwd', 1, Parser::class . '->parse', true),
        ]);

        $this->assertSame([], (new CodeContext('/nonexistent/base'))->collect($incident));
    }

    /**
     * Framework internals are excluded deliberately: the model already knows
     * what Cache::remember does, and sending it is noise and money.
     */
    public function test_it_skips_vendor(): void
    {
        $incident = new Incident('RuntimeException', 'x', [
            new Frame(
                dirname(__DIR__, 2) . '/vendor/phpunit/phpunit/src/Framework/TestCase.php',
                10,
                \PHPUnit\Framework\TestCase::class . '->runTest',
                true,
            ),
        ]);

        $this->assertSame([], (new CodeContext(dirname(__DIR__, 2)))->collect($incident));
    }

    public function test_it_collects_nothing_when_switched_off(): void
    {
        $this->assertSame([], (new CodeContext(dirname(__DIR__, 2), false))->collect($this->throwingLine()));
    }

    /** A closure has no reflectable name; that is a miss, not a crash. */
    public function test_a_closure_frame_is_survivable(): void
    {
        $incident = new Incident('RuntimeException', 'x', [
            new Frame(self::FIXTURE, 30, '{closure}', true),
        ]);

        $this->assertSame([], (new CodeContext(dirname(__DIR__, 2)))->collect($incident));
    }

    /** It rides the queue inside context, so it must survive serialisation. */
    public function test_collected_code_survives_a_queue_round_trip(): void
    {
        $incident = $this->throwingLine()->withCode($this->collect($this->throwingLine()));

        $restored = Incident::fromArray($incident->toArray());

        $this->assertSame($incident->code(), $restored->code());
    }

    /** Code must never be rendered as part of the request facts. */
    public function test_code_is_kept_out_of_the_context_facts(): void
    {
        $incident = (new Incident('RuntimeException', 'x', [], null, null, null, 'error', ['method' => 'GET']))
            ->withCode(['A::b' => 'source']);

        $this->assertArrayNotHasKey(Incident::CODE_KEY, $incident->facts());
        $this->assertSame(['method' => 'GET'], $incident->facts());
        $this->assertSame(['A::b' => 'source'], $incident->code());
    }

    /**
     * @return array<string, string>
     */
    private function collect(Incident $incident): array
    {
        return (new CodeContext(dirname(__DIR__, 2)))->collect($incident);
    }

    private function throwingLine(): Incident
    {
        $line = 0;

        foreach (file(self::FIXTURE) as $i => $text) {
            if (str_contains($text, "'not a month'")) {
                $line = $i + 1;
            }
        }

        $this->assertGreaterThan(0, $line, 'fixture no longer contains the throw');

        return new Incident('RuntimeException', 'not a month', [
            new Frame(realpath(self::FIXTURE), $line, Parser::class . '->parse', true),
        ]);
    }
}
