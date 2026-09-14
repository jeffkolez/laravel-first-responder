<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JeffKolez\FirstResponder\FirstResponder;
use JeffKolez\FirstResponder\Notifications\IncidentReported;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Tests\TestCase;
use RuntimeException;

/**
 * "The error is useless without the data that caused it."
 *
 * This is that complaint, as a test. A real route, a real request, a real bad
 * value in the URL — and an alert that names it. Every assertion here is about
 * something a person reading their phone at 11:35am can act on without opening
 * a log.
 */
class RequestContextTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('first-responder.queue', null);
    }

    protected function defineRoutes($router): void
    {
        // Modelled on the route that prompted all of this: a URL segment is
        // parsed, the parse fails, and the exception says nothing about what
        // it was given.
        $router->get('/date/{date}', function (string $date) {
            if (! preg_match('/^([a-zA-Z]+)(\d+)$/', $date)) {
                app(FirstResponder::class)->report(new RuntimeException('Invalid date format'));

                return 'reported';
            }

            return 'ok';
        })->name('events.date');
    }

    /** The URL is the single most useful fact about a GET that broke. */
    public function test_it_captures_the_url_of_the_request_that_broke(): void
    {
        $incident = $this->incidentFrom('/date/banana-19?tz=UTC');

        $this->assertStringContainsString('/date/banana-19', (string) $incident->url);
        $this->assertSame('GET', $incident->context['method']);
        $this->assertSame('events.date', $incident->context['route']);
    }

    /**
     * Route parameters are the application's own answer to "which part of this
     * URL did I care about", which makes them the best guess at the bad value.
     */
    public function test_it_captures_route_parameters_and_query_string(): void
    {
        $incident = $this->incidentFrom('/date/banana-19?tz=UTC');

        $this->assertSame('banana-19', $incident->context['route_params']['date']);
        $this->assertSame('UTC', $incident->context['query']['tz']);
    }

    /** The point of the whole exercise: the bad value is in the message. */
    public function test_the_alert_text_names_the_offending_value(): void
    {
        $text = (new IncidentReported($this->incidentFrom('/date/banana-19')))->toText();

        $this->assertStringContainsString('GET', $text);
        $this->assertStringContainsString('/date/banana-19', $text);
        $this->assertStringContainsString("date: 'banana-19'", $text);
    }

    /** And the model is shown it, so it stops asking to be shown it. */
    public function test_the_prompt_carries_the_request(): void
    {
        $prompt = app(\JeffKolez\FirstResponder\Support\PromptBuilder::class)
            ->user($this->incidentFrom('/date/banana-19'));

        $this->assertStringContainsString('banana-19', $prompt);
        $this->assertStringContainsString('REQUEST AND CONTEXT', $prompt);
    }

    /** The body is private until somebody says otherwise. */
    public function test_it_does_not_capture_the_request_body_by_default(): void
    {
        Route::post('/login', function () {
            app(FirstResponder::class)->report(new RuntimeException('boom'));

            return 'reported';
        });

        $incident = $this->incidentFrom('/login', 'post', ['password' => 'hunter2']);

        $this->assertArrayNotHasKey('body', $incident->context);
    }

    public function test_it_masks_excepted_body_fields_when_capture_is_switched_on(): void
    {
        $this->reconfigure(['first-responder.capture.body' => true]);

        Route::post('/login', function () {
            app(FirstResponder::class)->report(new RuntimeException('boom'));

            return 'reported';
        });

        $incident = $this->incidentFrom('/login', 'post', [
            'email' => 'jeff@example.com',
            'password' => 'hunter2',
        ]);

        $this->assertArrayHasKey('email', $incident->context['body']);
        $this->assertArrayNotHasKey('password', $incident->context['body']);
    }

    /**
     * A Sentry webhook is a request about an error, not the request that
     * caused it. Describing the webhook would replace a fact with an
     * irrelevance — and the URL is the fact people act on.
     */
    public function test_it_does_not_attach_the_current_request_to_an_incident_it_was_handed(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        Route::post('/webhook', function () {
            app(FirstResponder::class)->report(new Incident(
                'RuntimeException',
                'from elsewhere',
                [],
                'https://original.example.com/broke',
            ));

            return 'reported';
        });

        $this->post('/webhook')->assertOk();

        $incident = $this->lastIncident();

        $this->assertSame('https://original.example.com/broke', $incident->url);
        $this->assertArrayNotHasKey('method', $incident->context);
    }

    public function test_capture_can_be_switched_off_entirely(): void
    {
        $this->reconfigure(['first-responder.capture.enabled' => false]);

        $this->assertNull($this->incidentFrom('/date/banana-19')->url);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function incidentFrom(string $uri, string $method = 'get', array $data = []): Incident
    {
        \Illuminate\Support\Facades\Bus::fake();

        $this->{$method}($uri, $data)->assertOk();

        return $this->lastIncident();
    }

    private function lastIncident(): Incident
    {
        $dispatched = null;

        \Illuminate\Support\Facades\Bus::assertDispatched(
            \JeffKolez\FirstResponder\Jobs\RespondToIncident::class,
            function ($job) use (&$dispatched) {
                $dispatched = $job;

                return true;
            }
        );

        return Incident::fromArray($dispatched->payload);
    }
}
