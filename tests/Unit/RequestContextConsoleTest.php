<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use JeffKolez\FirstResponder\Support\RequestContext;
use PHPUnit\Framework\TestCase;

/**
 * Off the request, there is no request.
 *
 * Tested against a bare container rather than through Testbench, because
 * Testbench boots its application with a synthetic HTTP request bound whether
 * or not the test makes one — so a test run inside it can only ever prove
 * what Testbench did, not what a queue worker does.
 *
 * What a queue worker actually does is have `Request::capture()` in the
 * container, built from CLI globals. Ask it for a URL and it answers
 * "http://:" without hesitation. An alert carrying that is worse than an alert
 * carrying no URL, because it reads like a fact.
 */
class RequestContextConsoleTest extends TestCase
{
    public function test_it_reports_no_url_for_a_request_captured_off_the_command_line(): void
    {
        $container = new Container();
        $container->instance('request', Request::capture());

        $captured = (new RequestContext($container))->capture();

        $this->assertNull($captured['url']);
        $this->assertArrayNotHasKey('method', $captured['context']);
    }

    /** What broke off the request is a command, so name the command. */
    public function test_it_records_the_command_line_instead(): void
    {
        $container = new Container();
        $container->instance('request', Request::capture());

        $captured = (new RequestContext($container))->capture();

        $this->assertArrayHasKey('command', $captured['context']);
        $this->assertStringContainsString('phpunit', $captured['context']['command']);
    }

    /** No request bound at all is the same answer, not an error. */
    public function test_it_survives_a_container_with_no_request_at_all(): void
    {
        $captured = (new RequestContext(new Container()))->capture();

        $this->assertNull($captured['url']);
    }

    /**
     * This runs inside the exception handler, while the application is already
     * failing. A container that throws on resolve — a dead session or database
     * behind the auth guard, say — must never turn a handled exception into an
     * unhandled one, and must not take the rest of the report down with it.
     */
    public function test_a_container_that_throws_costs_nothing(): void
    {
        $container = new Container();
        $container->bind('request', function () {
            throw new \RuntimeException('the container is on fire');
        });

        $captured = (new RequestContext($container))->capture();

        $this->assertNull($captured['url']);

        // It still degrades to what it can see without the container.
        $this->assertArrayHasKey('command', $captured['context']);
    }
}
