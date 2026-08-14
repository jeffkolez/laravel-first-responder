<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use JeffKolez\FirstResponder\Jobs\RespondToIncident;
use JeffKolez\FirstResponder\Notifications\IncidentReported;
use JeffKolez\FirstResponder\Tests\TestCase;

/**
 * The command exists to be run on a machine somebody is trying to configure —
 * usually production, usually because nothing is arriving and they cannot tell
 * which link is broken. So the thing under test is mostly its diagnostics: it
 * has to fail loudly and name the cause, not exit 0 having done nothing.
 */
class TestCommandTest extends TestCase
{
    public function test_it_sends_a_notification(): void
    {
        Notification::fake();

        $this->artisan('first-responder:test', ['--no-ai' => true])
            ->assertSuccessful();

        Notification::assertSentOnDemand(IncidentReported::class);
    }

    /**
     * The gates exist to SUPPRESS reports. A test command subject to them would
     * do nothing on any machine that isn't production, and nothing the second
     * time you ran it anywhere — useless in exactly the situation it is for.
     */
    public function test_it_runs_even_though_the_environment_gate_excludes_testing(): void
    {
        Notification::fake();
        $this->reconfigure(['first-responder.environments' => ['production']]);

        $this->artisan('first-responder:test', ['--no-ai' => true])
            ->expectsOutputToContain('bypassed')
            ->assertSuccessful();

        Notification::assertSentOnDemand(IncidentReported::class);
    }

    public function test_it_is_not_silenced_by_the_dedupe_window(): void
    {
        Notification::fake();

        $this->artisan('first-responder:test', ['--no-ai' => true])->assertSuccessful();
        $this->artisan('first-responder:test', ['--no-ai' => true])->assertSuccessful();
        $this->artisan('first-responder:test', ['--no-ai' => true])->assertSuccessful();

        Notification::assertSentOnDemandTimes(IncidentReported::class, 3);
    }

    public function test_dry_run_sends_nothing(): void
    {
        Notification::fake();

        $this->artisan('first-responder:test', ['--no-ai' => true, '--dry' => true])
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    /**
     * The failure this command exists to catch. A channel with no route is
     * completely silent at runtime — the channel is invoked, finds no
     * destination and returns — so the command must fail rather than report
     * success having delivered nowhere.
     */
    public function test_it_fails_when_a_channel_has_no_route(): void
    {
        Notification::fake();
        $this->reconfigure([
            'first-responder.notifications.channels' => ['mail'],
            'first-responder.notifications.routes' => ['mail' => null],
        ]);

        $this->artisan('first-responder:test')->assertFailed();

        Notification::assertNothingSent();
    }

    public function test_it_fails_when_no_channels_are_configured(): void
    {
        Notification::fake();
        $this->reconfigure(['first-responder.notifications.channels' => []]);

        $this->artisan('first-responder:test')->assertFailed();

        Notification::assertNothingSent();
    }

    /**
     * Inline delivery proves config and credentials. It does NOT prove a worker
     * is consuming the queue, which is how production actually runs — so
     * --queue hands the real job to the real queue instead.
     */
    public function test_the_queue_option_dispatches_the_real_job(): void
    {
        Bus::fake();
        Notification::fake();

        $this->artisan('first-responder:test', ['--queue' => true])->assertSuccessful();

        Bus::assertDispatched(RespondToIncident::class);
        // Delivery is the worker's job now, not the command's.
        Notification::assertNothingSent();
    }

    public function test_the_message_is_shown_so_a_failed_send_still_tells_you_what_it_would_have_said(): void
    {
        Notification::fake();

        $this->artisan('first-responder:test', ['--no-ai' => true, '--dry' => true])
            ->expectsOutputToContain('RuntimeException')
            ->assertSuccessful();
    }
}
