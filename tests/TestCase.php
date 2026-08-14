<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests;

use JeffKolez\FirstResponder\FirstResponderServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FirstResponderServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The package only reports in `production` by default, and Testbench
        // boots as `testing`. Overriding here rather than in each test keeps
        // every test from having to remember it.
        $app['config']->set('first-responder.environments', []);
        $app['config']->set('first-responder.diagnostician', 'null');
        $app['config']->set('first-responder.notifications.channels', ['mail']);
        $app['config']->set('first-responder.notifications.routes', ['mail' => 'ops@example.com']);
        $app['config']->set('cache.default', 'array');
    }

    /**
     * Change config and rebuild the objects that read it at construction time.
     *
     * FirstResponder, Gatekeeper and the Diagnostician are singletons resolved
     * from config once. Setting config after they have been resolved silently
     * does nothing, which produces tests that pass for the wrong reason — so
     * every config change in a test goes through here.
     *
     * @param  array<string, mixed>  $values
     */
    protected function withConfig(array $values): void
    {
        foreach ($values as $key => $value) {
            config()->set($key, $value);
        }

        foreach ([
            \JeffKolez\FirstResponder\FirstResponder::class,
            \JeffKolez\FirstResponder\Support\Gatekeeper::class,
            \JeffKolez\FirstResponder\Support\Redactor::class,
            \JeffKolez\FirstResponder\Support\SourceExtractor::class,
            \JeffKolez\FirstResponder\Support\PromptBuilder::class,
            \JeffKolez\FirstResponder\Contracts\Diagnostician::class,
            'first-responder',
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }
}
