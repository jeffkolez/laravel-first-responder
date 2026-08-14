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

        return array_values(array_diff($channels, $routed));
    }
}
