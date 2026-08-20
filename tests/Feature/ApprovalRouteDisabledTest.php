<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Feature;

use JeffKolez\FirstResponder\Tests\TestCase;

/**
 * A half-configured install must not leave a route standing open that adds
 * labels to somebody's repository.
 *
 * A separate class for the same reason as SentryIngestDisabledTest: routes are
 * registered during boot from config, and refreshApplication() re-runs
 * defineEnvironment, so the only honest way to test boot-time behaviour is to
 * boot with the config you mean.
 */
class ApprovalRouteDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Switched on, no secret — the dangerous half-configured state.
        $app['config']->set('first-responder.approvals.enabled', true);
        $app['config']->set('first-responder.approvals.secret', '');
    }

    public function test_no_route_is_registered_without_a_secret(): void
    {
        $this->assertFalse(
            $this->app['router']->getRoutes()->hasNamedRoute('first-responder.approve')
        );
    }

    public function test_the_endpoint_is_not_reachable(): void
    {
        $this->postJson('/first-responder/approve', [])->assertNotFound();
    }
}
