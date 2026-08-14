<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use JeffKolez\FirstResponder\Jobs\RespondToIncident;
use JeffKolez\FirstResponder\Tests\TestCase;

/**
 * The Sentry route is public — Sentry cannot send an arbitrary auth header — so
 * the HMAC is the only thing in front of it. These tests exist mostly to make
 * sure that check cannot be weakened by accident.
 */
class SentryIngestTest extends TestCase
{
    private const SECRET = 'test-client-secret';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('first-responder.sentry.enabled', true);
        $app['config']->set('first-responder.sentry.secret', self::SECRET);
        $app['config']->set('first-responder.sentry.middleware', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $issueId = '1117540176'): array
    {
        return [
            'action' => 'triggered',
            'data' => ['event' => [
                'issue_id' => $issueId,
                'title' => 'ReferenceError: heck is not defined',
                'level' => 'error',
                'web_url' => 'https://sentry.io/issues/' . $issueId . '/',
                'request' => ['url' => 'https://site.test/p/1'],
                'exception' => ['values' => [[
                    'type' => 'ReferenceError',
                    'value' => 'heck is not defined',
                    'stacktrace' => ['frames' => [
                        ['filename' => 'vendor/boot.php', 'lineno' => 1, 'in_app' => false],
                        [
                            'filename' => 'app/Http/Controllers/ProfileController.php',
                            'lineno' => 42,
                            'in_app' => true,
                            'context_line' => 'return $k->name;',
                        ],
                    ]],
                ]]],
            ]],
        ];
    }

    private function post(array $payload, ?string $signature = null, string $resource = 'event_alert')
    {
        $body = json_encode($payload);

        return $this->call('POST', '/first-responder/sentry', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SENTRY_HOOK_RESOURCE' => $resource,
            'HTTP_SENTRY_HOOK_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    public function test_a_correctly_signed_alert_is_accepted(): void
    {
        Bus::fake();

        $this->post($this->payload())->assertStatus(202);

        Bus::assertDispatched(RespondToIncident::class, function (RespondToIncident $job) {
            return $job->payload['external_id'] === '1117540176'
                && $job->payload['type'] === 'ReferenceError';
        });
    }

    public function test_a_bad_signature_is_rejected(): void
    {
        Bus::fake();

        $this->post($this->payload(), 'deadbeef')->assertStatus(404);

        Bus::assertNothingDispatched();
    }

    public function test_a_missing_signature_is_rejected(): void
    {
        Bus::fake();

        $body = json_encode($this->payload());

        $this->call('POST', '/first-responder/sentry', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SENTRY_HOOK_RESOURCE' => 'event_alert',
        ], $body)->assertStatus(404);

        Bus::assertNothingDispatched();
    }

    /**
     * The signature covers raw bytes. This is the regression guard against
     * someone "tidying" the controller into hashing a re-encoded array — which
     * reorders keys and would break every real delivery.
     */
    public function test_the_signature_is_bound_to_the_exact_bytes(): void
    {
        Bus::fake();

        $payload = $this->payload();
        $compactSignature = hash_hmac('sha256', json_encode($payload), self::SECRET);

        $this->call('POST', '/first-responder/sentry', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SENTRY_HOOK_RESOURCE' => 'event_alert',
            'HTTP_SENTRY_HOOK_SIGNATURE' => $compactSignature,
        ], json_encode($payload, JSON_PRETTY_PRINT))->assertStatus(404);

        Bus::assertNothingDispatched();
    }

    /** Signed but not a resource we handle: acknowledge so Sentry stops retrying. */
    public function test_an_unhandled_resource_is_acknowledged(): void
    {
        Bus::fake();

        $this->post($this->payload(), null, 'installation')
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        Bus::assertNothingDispatched();
    }

    /** Sentry-sourced incidents go through the same dedupe as native ones. */
    public function test_repeat_alerts_for_one_issue_are_deduplicated(): void
    {
        Bus::fake();

        $this->post($this->payload())->assertStatus(202);
        $this->post($this->payload())->assertStatus(200);
        $this->post($this->payload())->assertStatus(200);

        Bus::assertDispatchedTimes(RespondToIncident::class, 1);
    }

    /**
     * A half-configured install must not leave an unauthenticated public
     * endpoint lying around, so the route is only registered with a secret.
     */
    public function test_the_route_does_not_exist_without_a_secret(): void
    {
        config()->set('first-responder.sentry.secret', '');
        $this->refreshApplication();

        $this->post($this->payload())->assertNotFound();

        $this->assertFalse(
            $this->app['router']->getRoutes()->hasNamedRoute('first-responder.sentry')
        );
    }
}
