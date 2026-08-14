<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use JeffKolez\FirstResponder\FirstResponder;
use JeffKolez\FirstResponder\Jobs\RespondToIncident;
use JeffKolez\FirstResponder\Support\Redactor;
use JeffKolez\FirstResponder\Tests\TestCase;
use RuntimeException;

/**
 * The report() pipeline — the part where a mistake is expensive rather than
 * merely wrong. Every test here is guarding against a specific failure that
 * costs money, leaks a secret, or floods a channel.
 */
class FirstResponderTest extends TestCase
{
    public function test_it_dispatches_a_job_for_a_reportable_exception(): void
    {
        Bus::fake();

        $accepted = app(FirstResponder::class)->report(new RuntimeException('boom'));

        $this->assertTrue($accepted);
        Bus::assertDispatched(RespondToIncident::class);
    }

    public function test_it_ignores_configured_exception_types(): void
    {
        Bus::fake();

        $accepted = app(FirstResponder::class)->report(
            ValidationException::withMessages(['email' => 'bad'])
        );

        $this->assertFalse($accepted);
        Bus::assertNothingDispatched();
    }

    /** Subclass matching is what people expect when they ignore a base class. */
    public function test_ignoring_a_base_class_also_ignores_its_subclasses(): void
    {
        Bus::fake();
        $this->withConfig(['first-responder.ignore' => [RuntimeException::class]]);

        // OutOfBoundsException extends RuntimeException — must be ignored.
        $this->assertFalse(
            app(FirstResponder::class)->report(new \OutOfBoundsException('is a RuntimeException'))
        );

        // LogicException does not — must still be reported.
        $this->assertTrue(
            app(FirstResponder::class)->report(new \LogicException('is not a RuntimeException'))
        );

        Bus::assertDispatchedTimes(RespondToIncident::class, 1);
    }

    public function test_it_stays_silent_outside_the_configured_environments(): void
    {
        Bus::fake();
        $this->withConfig(['first-responder.environments' => ['production']]);

        $this->assertFalse(app(FirstResponder::class)->report(new RuntimeException('boom')));
        Bus::assertNothingDispatched();
    }

    public function test_the_master_switch_turns_everything_off(): void
    {
        Bus::fake();
        $this->withConfig(['first-responder.enabled' => false]);

        $this->assertFalse(app(FirstResponder::class)->report(new RuntimeException('boom')));
        Bus::assertNothingDispatched();
    }

    /**
     * The dedupe has to happen BEFORE dispatch. If it happened inside the job,
     * a storm would still create one job per occurrence — the queue would
     * absorb the flood instead of the channel, which is not the point.
     */
    public function test_a_repeated_error_is_deduplicated_before_a_job_is_created(): void
    {
        Bus::fake();

        $responder = app(FirstResponder::class);

        // Same class, same line — one bug, however many times it fires.
        for ($i = 0; $i < 5; $i++) {
            $responder->report(new RuntimeException("attempt {$i}"));
        }

        Bus::assertDispatchedTimes(RespondToIncident::class, 1);
    }

    /**
     * Dedupe bounds repeats of ONE error. The budget is what bounds a deploy
     * that breaks many different things at once.
     */
    public function test_the_hourly_budget_caps_reports(): void
    {
        Bus::fake();

        // Dedupe off so the budget is unambiguously what does the limiting.
        $this->withConfig([
            'first-responder.max_per_hour' => 3,
            'first-responder.dedupe_minutes' => 0,
        ]);

        $responder = app(FirstResponder::class);
        $accepted = 0;

        for ($i = 0; $i < 10; $i++) {
            if ($responder->report(new RuntimeException('e' . $i))) {
                $accepted++;
            }
        }

        $this->assertSame(3, $accepted, 'The hourly budget should have capped this at 3.');
        Bus::assertDispatchedTimes(RespondToIncident::class, 3);
    }

    /**
     * The single most important test in the package.
     *
     * Redaction must happen before serialisation, because the queue payload is
     * written to Redis or a database table. Redacting inside the job would
     * protect the model and leave the secret sitting in `jobs`.
     */
    public function test_secrets_are_redacted_before_the_payload_reaches_the_queue(): void
    {
        Bus::fake();

        $secret = 'sk-abcdefghijklmnopqrstuvwxyz012345';

        app(FirstResponder::class)->report(
            new RuntimeException("failed calling api with {$secret}"),
            ['authorization' => "Bearer {$secret}"]
        );

        Bus::assertDispatched(RespondToIncident::class, function (RespondToIncident $job) use ($secret) {
            $encoded = json_encode($job->payload);

            $this->assertStringNotContainsString($secret, (string) $encoded);
            $this->assertStringContainsString(Redactor::MASK, (string) $encoded);

            return true;
        });
    }

    public function test_redaction_can_be_disabled_explicitly(): void
    {
        Bus::fake();
        $this->withConfig(['first-responder.redact' => false]);

        app(FirstResponder::class)->report(new RuntimeException('token sk-abcdefghijklmnopqrstuvwxyz012345'));

        Bus::assertDispatched(RespondToIncident::class, function (RespondToIncident $job) {
            return str_contains((string) json_encode($job->payload), 'sk-abcdef');
        });
    }

    public function test_source_context_is_attached_at_capture_time(): void
    {
        Bus::fake();

        app(FirstResponder::class)->report(new RuntimeException('boom'));

        Bus::assertDispatched(RespondToIncident::class, function (RespondToIncident $job) {
            $frames = $job->payload['frames'] ?? [];

            // The throw site is this test file, which lives under base_path in
            // Testbench, so the extractor should have read real lines from it.
            return isset($frames[0]['context_line']) && $frames[0]['context_line'] !== null;
        });
    }
}
