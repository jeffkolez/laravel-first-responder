<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Capability tokens for the buttons on an alert.
 *
 * Telegram caps `callback_data` at 64 bytes, and a repository name plus an
 * issue number plus a signature does not comfortably fit — "owner/some-long-
 * frontend-repo" alone can eat a third of the budget. So nothing meaningful
 * travels in the button at all. A token is an opaque random string; the cache
 * holds what it refers to.
 *
 * That makes the token itself the capability: 96 bits of randomness, unguessable,
 * and revocable by deleting one cache key. It is checked in addition to
 * Telegram's own webhook secret, not instead of it.
 *
 * Note what a token does NOT carry: an issue number. The chat message is
 * usually rendered before the issue is filed — channel order is whatever the
 * user configured — so a token points at a fingerprint, and the issue is looked
 * up when the button is actually pressed. That also means the button keeps
 * working if the issue is filed late, or renumbered, or filed by a different
 * mechanism entirely.
 */
final class ApprovalTokens
{
    private const PREFIX = 'first-responder:approval:';

    public function __construct(
        private readonly Cache $cache,
        private readonly bool $enabled = false,
        private readonly int $ttlHours = 168,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Mint a token for one incident. Returns null when approvals are off, so
     * callers can treat "no token" as "render the message without buttons".
     */
    public function mint(string $repo, string $fingerprint): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        $token = bin2hex(random_bytes(12));

        $this->cache->put(
            self::PREFIX . $token,
            ['repo' => $repo, 'fingerprint' => $fingerprint],
            max(1, $this->ttlHours) * 3600
        );

        return $token;
    }

    /**
     * @return array{repo: string, fingerprint: string}|null
     */
    public function resolve(string $token): ?array
    {
        // The token arrives from a webhook body. Reject anything that is not
        // the shape we mint before it is concatenated into a cache key.
        if (preg_match('/^[a-f0-9]{24}$/', $token) !== 1) {
            return null;
        }

        $payload = $this->cache->get(self::PREFIX . $token);

        if (! is_array($payload)
            || ! is_string($payload['repo'] ?? null)
            || ! is_string($payload['fingerprint'] ?? null)) {
            return null;
        }

        return ['repo' => $payload['repo'], 'fingerprint' => $payload['fingerprint']];
    }

    /**
     * Tokens are deliberately NOT single-use.
     *
     * The obvious design burns the token on first press, but Telegram retries a
     * webhook it considers failed, and a double-tap on a phone is common. Both
     * would then produce a second press that resolves to nothing and reports an
     * error to somebody who did exactly the right thing. Every action behind
     * these tokens is idempotent — adding a label that is already there, muting
     * something already muted — so replay is harmless and the friendlier
     * behaviour is also the correct one.
     */
    public function forget(string $token): void
    {
        $this->cache->forget(self::PREFIX . $token);
    }
}
