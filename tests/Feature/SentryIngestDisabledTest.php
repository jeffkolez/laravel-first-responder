<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Feature;

use JeffKolez\FirstResponder\Tests\TestCase;

/**
 * The route must not exist on a half-configured install.
 *
 * A SEPARATE CLASS rather than a case inside SentryIngestTest, because routes
 * are registered during boot from config. Flipping the secret mid-test and
 * calling refreshApplication() does not work: refreshApplication re-runs
 * defineEnvironment, which puts the secret straight back — the test would then
 * pass or fail for reasons unrelated to what it claims to check. The only
 * honest way to test boot-time behaviour is to boot with the config you mean.
 */
class SentryIngestDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Enabled but with no secret — the dangerous half-configured state.
        $app['config']->set('first-responder.sentry.enabled', true);
        $app['config']->set('first-responder.sentry.secret', '');
    }

    public function test_no_route_is_registered_without_a_secret(): void
    {
        $this->assertFalse(
            $this->app['router']->getRoutes()->hasNamedRoute('first-responder.sentry'),
            'An enabled-but-secretless install must not expose the ingest route.'
        );
    }

    public function test_the_endpoint_is_not_reachable(): void
    {
        $this->call('POST', '/first-responder/sentry', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SENTRY_HOOK_RESOURCE' => 'event_alert',
        ], '{}')->assertNotFound();
    }
}
