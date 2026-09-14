<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Unit;

use JeffKolez\FirstResponder\Support\Frame;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\Redactor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The values that were in play when it broke.
 *
 * An alert saying only "Invalid date format at EventController.php:72" tells
 * you a bug exists somewhere you already knew to look. Everything tested here
 * exists to put the offending value in the alert itself.
 */
class IncidentDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // php.ini-production strips trace arguments, and this container's ini
        // agrees. The setting is PHP_INI_ALL, so it can be turned on for the
        // duration of the test — which is the only way to assert on the
        // behaviour at all.
        ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_restore('zend.exception_ignore_args');

        parent::tearDown();
    }

    /**
     * The one that matters.
     *
     * PHP's trace is offset by one: entry 0's file/line is the CALLER, while
     * its function and arguments describe the code that threw. Incident
     * recombines them, so the frame pointing at the throwing line is also the
     * frame carrying the values it was called with. Get the offset wrong and
     * every alert names the right line with the wrong data, which is worse
     * than naming no data at all.
     */
    public function test_the_throwing_frame_carries_the_arguments_it_was_called_with(): void
    {
        $incident = Incident::fromThrowable($this->brokenCall('banana19'));

        $frame = $incident->significantFrames(1)[0];

        $this->assertSame(__FILE__, $frame->file);
        $this->assertStringContainsString('reject(', (string) $frame->signature());
        $this->assertStringContainsString("'banana19'", (string) $frame->signature());
    }

    public function test_it_renders_an_object_argument_as_its_class_and_nothing_else(): void
    {
        // A model rendered in full is every column of a row, posted to a chat
        // channel and a third-party model. The class name is the whole budget.
        $incident = Incident::fromThrowable($this->brokenCall(new \stdClass()));

        $signature = (string) $incident->significantFrames(1)[0]->signature();

        $this->assertStringContainsString('stdClass', $signature);
        $this->assertStringNotContainsString('{', $signature);
    }

    public function test_it_truncates_a_long_string_argument(): void
    {
        $incident = Incident::fromThrowable($this->brokenCall(str_repeat('a', 500)));

        $signature = (string) $incident->significantFrames(1)[0]->signature();

        $this->assertLessThan(200, mb_strlen($signature));
        $this->assertStringContainsString('…', $signature);
    }

    /** A wrapper says what failed; the innermost one says why. */
    public function test_it_records_the_previous_exception_chain(): void
    {
        $root = new RuntimeException('Connection refused');
        $wrapped = new \PDOException('SQLSTATE[HY000]', 0, $root);

        $incident = Incident::fromThrowable(new RuntimeException('Query failed', 0, $wrapped));

        $this->assertCount(2, $incident->context['previous']);
        $this->assertStringContainsString('SQLSTATE[HY000]', $incident->context['previous'][0]);
        $this->assertStringContainsString('Connection refused', $incident->context['previous'][1]);
    }

    public function test_it_never_overwrites_context_the_caller_supplied(): void
    {
        $incident = Incident::fromThrowable(
            new RuntimeException('x', 0, new RuntimeException('y')),
            ['previous' => 'mine'],
        );

        $this->assertSame('mine', $incident->context['previous']);
    }

    public function test_an_exception_with_no_chain_adds_no_previous_key(): void
    {
        $this->assertArrayNotHasKey('previous', Incident::fromThrowable(new RuntimeException('x'))->context);
    }

    /**
     * Arguments are the likeliest place in a stack trace for a real credential
     * to sit in plain text — a token handed to a client, a password handed to
     * a hasher. Redaction has to reach them.
     */
    public function test_the_redactor_masks_frame_arguments(): void
    {
        $redactor = new Redactor([], ['hunter2-the-real-secret']);

        $incident = new Incident('RuntimeException', 'boom', [
            new Frame('/app/X.php', 10, 'login', true, [], null, [], ["'hunter2-the-real-secret'"]),
        ]);

        $masked = $redactor->redactIncident($incident);

        $this->assertStringNotContainsString('hunter2', $masked->frames[0]->args[0]);
        $this->assertStringContainsString(Redactor::MASK, $masked->frames[0]->args[0]);
    }

    public function test_a_frame_survives_a_queue_round_trip_with_its_arguments(): void
    {
        $incident = Incident::fromThrowable($this->brokenCall('banana19'));

        $restored = Incident::fromArray($incident->toArray());

        $this->assertSame(
            $incident->significantFrames(1)[0]->signature(),
            $restored->significantFrames(1)[0]->signature(),
        );
    }

    /** Sentry sends named locals, which beat positional arguments outright. */
    public function test_it_reads_sentry_frame_variables_as_named_arguments(): void
    {
        $frame = Frame::fromArray([
            'filename' => '/app/X.php',
            'lineno' => 72,
            'function' => 'date',
            'vars' => ['$date' => 'banana19', '$request' => ['too' => 'big']],
        ]);

        $this->assertSame("date(\$date='banana19')", $frame->signature());
    }

    public function test_a_frame_with_no_function_has_no_signature(): void
    {
        $this->assertNull((new Frame('/app/X.php', 10))->signature());
    }

    private function brokenCall(mixed $value): \Throwable
    {
        try {
            $this->reject($value);
        } catch (\Throwable $e) {
            return $e;
        }

        $this->fail('reject() did not throw');
    }

    private function reject(mixed $value): void
    {
        throw new RuntimeException('Invalid date format');
    }
}
