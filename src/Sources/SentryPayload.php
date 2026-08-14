<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Sources;

use JeffKolez\FirstResponder\Support\Frame;
use JeffKolez\FirstResponder\Support\Incident;

/**
 * Turns a Sentry issue-alert webhook body into an Incident.
 *
 * Sentry orders stack frames outermost first, the opposite of a PHP throwable,
 * so they are reversed here. Skip that and every report leads with a framework
 * bootstrap file and buries the line that broke, which reads as the tool not
 * working.
 *
 * Sentry also ships pre/post source context captured at throw time, which is
 * better than reading the file later: it is a snapshot of the code that ran,
 * and it works for frames whose source does not exist on this machine at all (a
 * JavaScript frontend reporting into the same project, for instance).
 */
final class SentryPayload
{
    /**
     * @param  array<string, mixed>  $body
     */
    public static function toIncident(array $body): ?Incident
    {
        $event = $body['data']['event'] ?? null;

        if (! is_array($event)) {
            return null;
        }

        $values = $event['exception']['values'] ?? [];
        $first = is_array($values) && $values !== [] ? end($values) : [];

        $type = (string) ($first['type'] ?? $event['metadata']['type'] ?? 'Error');
        $message = (string) (
            $first['value']
            ?? $event['metadata']['value']
            ?? $event['title']
            ?? $event['message']
            ?? ''
        );

        return new Incident(
            $type,
            $message,
            self::frames($values),
            self::url($event),
            isset($event['environment']) ? (string) $event['environment'] : null,
            isset($event['release']) ? (string) $event['release'] : null,
            (string) ($event['level'] ?? 'error'),
            self::context($event),
            isset($event['issue_id']) ? (string) $event['issue_id'] : null,
            isset($event['web_url']) ? (string) $event['web_url'] : null,
        );
    }

    /**
     * @param  mixed  $values
     * @return Frame[]
     */
    private static function frames(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $frames = [];

        foreach ($values as $value) {
            foreach ($value['stacktrace']['frames'] ?? [] as $frame) {
                if (is_array($frame)) {
                    $frames[] = Frame::fromArray($frame);
                }
            }
        }

        // Sentry: outermost first. Us: innermost first.
        return array_reverse($frames);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function url(array $event): ?string
    {
        $url = $event['request']['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private static function context(array $event): array
    {
        $context = [];

        // Tags arrive as [[key, value], …]. Flatten so the diagnosis prompt and
        // any custom channel see a normal map.
        foreach ($event['tags'] ?? [] as $tag) {
            if (is_array($tag) && count($tag) === 2) {
                $context['tags'][(string) $tag[0]] = $tag[1];
            }
        }

        if (isset($event['request']['method'])) {
            $context['method'] = $event['request']['method'];
        }

        return $context;
    }
}
