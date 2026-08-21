<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Unit;

use JeffKolez\FirstResponder\Support\Recipients;
use PHPUnit\Framework\TestCase;

/**
 * The unrouted-channel check behind first-responder:test.
 *
 * A channel named with no route is the commonest way to misconfigure this
 * package and it is completely silent at runtime — the channel is asked to
 * deliver, finds no destination, and returns. Surfacing it is most of what
 * makes the test command worth having.
 *
 * Which is exactly why a false positive here is expensive: it fails the setup
 * check, non-zero, on an installation that works.
 */
final class RecipientsTest extends TestCase
{
    public function test_it_names_a_channel_with_no_route(): void
    {
        $missing = Recipients::channelsMissingRoutes(
            ['mail', 'telegram'],
            ['mail' => 'ops@example.com']
        );

        $this->assertSame(['telegram'], $missing);
    }

    public function test_a_blank_route_counts_as_missing(): void
    {
        // env() returning null for an unset chat id is the real-world case.
        $missing = Recipients::channelsMissingRoutes(
            ['telegram'],
            ['telegram' => null]
        );

        $this->assertSame(['telegram'], $missing);
    }

    /**
     * The GitHub channel resolves its repository from its own config block and
     * from the incident's stack frames. It has nothing to be routed to, so
     * naming it must not report a misconfiguration that does not exist.
     */
    public function test_the_github_channel_does_not_need_a_route(): void
    {
        $missing = Recipients::channelsMissingRoutes(['github'], []);

        $this->assertSame([], $missing);
    }

    public function test_a_self_routing_channel_alongside_an_unrouted_one(): void
    {
        $missing = Recipients::channelsMissingRoutes(
            ['github', 'telegram'],
            []
        );

        $this->assertSame(['telegram'], $missing);
    }

    public function test_a_route_may_still_be_given_as_an_optional_default(): void
    {
        $missing = Recipients::channelsMissingRoutes(
            ['github'],
            ['github' => 'acme/api']
        );

        $this->assertSame([], $missing);
    }
}
