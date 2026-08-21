<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

use Illuminate\Notifications\AnonymousNotifiable;

/**
 * Builds the on-the-fly notifiable that reports are sent to.
 *
 * Anonymous, not a User model, because the audience for these is an ops channel
 * or somebody's DM, not a person with an account in the application.
 *
 * Extracted from the job so the test command sends through the same
 * construction. A test that builds its own recipient only proves the test
 * works.
 */
final class Recipients
{
    /**
     * @param  array<string, mixed>  $routes
     */
    public static function fromRoutes(array $routes): ?AnonymousNotifiable
    {
        $routes = array_filter(
            $routes,
            static fn ($route) => $route !== null && $route !== '' && $route !== []
        );

        if ($routes === []) {
            return null;
        }

        $notifiable = new AnonymousNotifiable();

        foreach ($routes as $channel => $route) {
            $notifiable->route((string) $channel, $route);
        }

        return $notifiable;
    }

    /**
     * Channels named in config that have no matching route.
     *
     * The most common way to configure this wrong, and silent at runtime: the
     * channel is asked to deliver, finds no destination, and returns. Surfacing
     * it is most of what makes the test command worth having.
     *
     * @param  string[]  $channels
     * @param  array<string, mixed>  $routes
     * @return string[]
     */
    public static function channelsMissingRoutes(array $channels, array $routes): array
    {
        $routed = array_keys(array_filter(
            $routes,
            static fn ($route) => $route !== null && $route !== '' && $route !== []
        ));

        return array_values(array_diff($channels, $routed, self::SELF_ROUTING));
    }

    /**
     * Channels that know their own destination.
     *
     * A notification route answers "where does this go", and for a chat channel
     * that is a chat id somebody has to supply. The GitHub channel works out
     * its repository from its own config block and from the incident's own
     * stack frames, so it has nothing to be routed to — a route is accepted as
     * an optional default, not required.
     *
     * Without this exemption, naming `github` in the channel list reports a
     * misconfiguration that does not exist, and first-responder:test exits
     * non-zero on a setup that works.
     */
    private const SELF_ROUTING = ['github'];
}
