<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Http\Controllers;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JeffKolez\FirstResponder\FirstResponder;
use JeffKolez\FirstResponder\Sources\SentryPayload;
use Psr\Log\LoggerInterface;

/**
 * Optional ingest for people who already run Sentry.
 *
 * Sentry's own chat notifications are a paid feature while webhooks are not, so
 * this exists to close that gap. It also means the package can add diagnosis to
 * an existing Sentry setup without replacing it.
 *
 * Authentication
 * --------------
 * Sentry cannot send an arbitrary header, so the HMAC signature is the only
 * thing in front of this endpoint. Two details that are easy to get wrong:
 *
 *  1. The digest is over the raw body. Sentry's published JS example hashes
 *     JSON.stringify(request.body), which round-trips there by luck; decoding
 *     and re-encoding in PHP reorders keys and changes spacing, and every
 *     signature would fail. We hash the untouched bytes.
 *  2. hash_equals, not ===, so the comparison is constant-time.
 *
 * Sentry treats a response slower than one second as a timeout, so this
 * validates, hands off, and returns.
 */
class SentryWebhookController
{
    /** Resources this acts on; the rest are acknowledged and dropped. */
    private const HANDLED = ['event_alert', 'error'];

    public function __invoke(
        Request $request,
        FirstResponder $responder,
        Dispatcher $bus,
        LoggerInterface $logger,
    ): JsonResponse {
        $secret = (string) config('first-responder.sentry.secret', '');

        if ($secret === '' || ! $this->signatureIsValid($request, $secret)) {
            $logger->warning('first-responder: rejected a Sentry webhook (bad or missing signature).', [
                'ip' => $request->ip(),
            ]);

            // 404 rather than 401: an unauthenticated caller learns nothing
            // about whether this path is a real endpoint.
            return response()->json(['error' => 'Not Found'], 404);
        }

        $resource = (string) $request->header('Sentry-Hook-Resource', '');

        if (! in_array($resource, self::HANDLED, true)) {
            // Signed and valid, just not ours. Return 200 so Sentry does not
            // flag the integration as failing and begin retrying.
            return response()->json(['status' => 'ignored', 'resource' => $resource]);
        }

        $incident = SentryPayload::toIncident((array) $request->json()->all());

        if ($incident === null) {
            return response()->json(['status' => 'ignored']);
        }

        // Straight through report(), so Sentry-sourced incidents get the same
        // treatment as native ones: same ignore list, same redaction, same
        // dedupe, same budget. A second path with its own rules would drift out
        // of step with this one.
        $accepted = $responder->report($incident);

        return response()->json(
            ['status' => $accepted ? 'queued' : 'skipped'],
            $accepted ? 202 : 200
        );
    }

    private function signatureIsValid(Request $request, string $secret): bool
    {
        $provided = (string) $request->header('Sentry-Hook-Signature', '');

        if ($provided === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $provided);
    }
}
