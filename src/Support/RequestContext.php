<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * What the application was doing when it broke.
 *
 * Why this exists
 * ---------------
 * An alert that says only "Exception: Invalid date format at
 * EventController.php:72" is not a bug report. It is a notification that a bug
 * report exists somewhere else. The reader's next move is always the same:
 * open the logs and find out what was being asked for. Every second between
 * the alert and that answer is a second the alert did not save anybody.
 *
 * Most of the time the answer is one line of request data. The URL alone
 * usually names the offending input, because the offending input usually
 * arrived in the URL.
 *
 * So this is captured at report time and travels with the incident: into the
 * prompt, so the model diagnoses against real values instead of guessing what
 * they might have been; into the chat message, so a person can often fix it
 * without opening anything.
 *
 * What it deliberately does not capture
 * -------------------------------------
 * The request body. Bodies carry passwords, card numbers and whatever else a
 * form collects, and the value of having them is far lower than the value of
 * the URL — a broken POST is usually reproducible from the route alone.
 * Redaction is good but it is a net, not a wall, and the safest data is the
 * data that was never collected. Set `capture.body` if you want it anyway.
 *
 * Failure policy
 * --------------
 * This runs inside the exception handler, while the application is already
 * failing. Anything here could itself throw — resolving the auth guard can hit
 * a session or a database that is exactly what just died. So every field is
 * fetched independently and a field that throws is simply absent. Losing the
 * user id must not cost you the URL, and nothing here may ever turn a handled
 * exception into an unhandled one.
 */
final class RequestContext
{
    /** Long enough for a user agent, short enough not to bury the alert. */
    private const MAX_VALUE_LENGTH = 300;

    /** A pathological query string is not worth an unbounded payload. */
    private const MAX_KEYS = 25;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $config = [],
    ) {
    }

    /**
     * @return array{url: ?string, context: array<string, mixed>}
     */
    public function capture(): array
    {
        if (! ($this->config['enabled'] ?? true)) {
            return ['url' => null, 'context' => []];
        }

        $request = $this->safely(fn () => $this->resolveRequest());

        if ($request === null) {
            return ['url' => null, 'context' => $this->console()];
        }

        return [
            'url' => $this->safely(fn () => (string) $request->fullUrl()),
            'context' => $this->http($request),
        ];
    }

    /**
     * The current HTTP request, or null when there isn't one.
     *
     * This is fiddlier than it looks, and getting it wrong is how a package
     * ends up reporting `GET http://:` against a failed queue job. A queue
     * worker and an artisan command both have a `request` in the container —
     * Request::capture() built from CLI globals, which answers every question
     * you ask it with a confident lie.
     *
     * The tempting check is runningInConsole(), and it is the wrong one: it
     * tests the SAPI, not whether an HTTP request exists. `artisan serve`,
     * Octane and the whole test suite run real requests under the CLI SAPI,
     * and all three would silently lose their URL.
     *
     * REQUEST_METHOD in the server bag is the honest signal. PHP sets it for
     * anything that arrived over HTTP and for nothing that did not.
     */
    private function resolveRequest(): ?object
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        if (! is_object($request) || ! method_exists($request, 'fullUrl')) {
            return null;
        }

        if (! isset($request->server) || ! $request->server->has('REQUEST_METHOD')) {
            return null;
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function http(object $request): array
    {
        $context = array_filter([
            'method' => $this->safely(fn () => (string) $request->getMethod()),
            'route' => $this->safely(fn () => $this->routeName($request)),
            'ip' => $this->safely(fn () => $request->ip()),
            'user_agent' => $this->safely(fn () => $request->headers->get('User-Agent')),
            'referer' => $this->safely(fn () => $request->headers->get('Referer')),
            'user_id' => $this->safely(fn () => $this->userId()),
        ], static fn ($v) => $v !== null && $v !== '');

        // Route parameters first: they are the segment of the URL the
        // application actually cared about, already pulled apart for you. For a
        // route like /date/{date}, this is the whole bug.
        $params = $this->safely(fn () => $this->routeParameters($request));

        if (is_array($params) && $params !== []) {
            $context['route_params'] = $this->flatten($params);
        }

        $query = $this->safely(fn () => $request->query());

        if (is_array($query) && $query !== []) {
            $context['query'] = $this->flatten($query);
        }

        if ($this->config['body'] ?? false) {
            $body = $this->safely(fn () => $this->body($request));

            if (is_array($body) && $body !== []) {
                $context['body'] = $this->flatten($body);
            }
        }

        return $context;
    }

    private function routeName(object $request): ?string
    {
        $route = $request->route();

        if (! is_object($route)) {
            return null;
        }

        $name = method_exists($route, 'getName') ? $route->getName() : null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $action = method_exists($route, 'getActionName') ? $route->getActionName() : null;

        return is_string($action) && $action !== '' && $action !== 'Closure' ? $action : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function routeParameters(object $request): array
    {
        $route = $request->route();

        if (! is_object($route) || ! method_exists($route, 'parameters')) {
            return [];
        }

        return (array) $route->parameters();
    }

    /**
     * @return array<string, mixed>
     */
    private function body(object $request): array
    {
        $except = array_map('strval', (array) ($this->config['body_except'] ?? []));

        $input = (array) $request->except($except);

        // Uploaded files serialise to nothing useful and can be enormous.
        return array_filter($input, static fn ($v) => ! is_object($v) || ! method_exists($v, 'getRealPath'));
    }

    private function userId(): int|string|null
    {
        if (! $this->container->bound('auth')) {
            return null;
        }

        $id = $this->container->make('auth')->id();

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function console(): array
    {
        $argv = $_SERVER['argv'] ?? null;

        if (! is_array($argv) || $argv === []) {
            return [];
        }

        $command = implode(' ', array_map('strval', array_slice($argv, 0, 20)));

        return ['command' => $this->truncate($command)];
    }

    /**
     * Reduce arbitrary values to something safe to serialise, log and print.
     *
     * Route model binding means `route_params` routinely contains Eloquent
     * models, and putting a model in an alert would send every attribute on
     * the row — including the columns you would never choose to send — to a
     * third-party model. So objects become their class name plus a key, and
     * nested arrays become a shape, not a dump.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function flatten(array $values): array
    {
        $out = [];

        foreach (array_slice($values, 0, self::MAX_KEYS, true) as $key => $value) {
            $out[$key] = $this->describe($value);
        }

        return $out;
    }

    private function describe(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->truncate($value);
        }

        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }

        if (is_object($value)) {
            $key = $this->safely(static function () use ($value) {
                return method_exists($value, 'getKey') ? $value->getKey() : null;
            });

            return $key === null || $key === ''
                ? $value::class
                : $value::class . '#' . $key;
        }

        return gettype($value);
    }

    private function truncate(string $value): string
    {
        return mb_strlen($value) > self::MAX_VALUE_LENGTH
            ? mb_substr($value, 0, self::MAX_VALUE_LENGTH - 1) . '…'
            : $value;
    }

    /**
     * Run a getter, and treat any failure as "this field is not available".
     *
     * @template T
     * @param  callable(): T  $get
     * @return T|null
     */
    private function safely(callable $get): mixed
    {
        try {
            return $get();
        } catch (Throwable) {
            return null;
        }
    }
}
