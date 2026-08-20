<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

use Illuminate\Contracts\Cache\Repository as Cache;
use JeffKolez\FirstResponder\Sinks\GitHubClient;

/**
 * Remembers which issue represents which fingerprint.
 *
 * Gatekeeper already deduplicates, but on a window measured in minutes. That is
 * the right shape for a chat message — you want to be told again tomorrow that
 * the site is still broken — and the wrong shape for an issue tracker, where
 * being told again tomorrow means a second issue, and by next week eleven.
 *
 * So this is a second, much longer-lived layer of deduplication with a
 * different job: not "have we mentioned this recently" but "does a ticket for
 * this already exist".
 *
 * Two tiers, in this order for a reason:
 *
 *  1. Cache. Immediate, and correct during the burst that matters.
 *  2. GitHub search. Survives a cache flush or a TTL expiry, but its index is
 *     asynchronous and lags by seconds to minutes — long enough that a
 *     search-only implementation would file duplicates during exactly the spike
 *     it exists to suppress.
 *
 * A miss on both is not an error. It means "file one", and the caller records
 * the result.
 */
final class IssueRegistry
{
    private const PREFIX = 'first-responder:gh:';

    public function __construct(
        private readonly Cache $cache,
        private readonly GitHubClient $github,
        private readonly int $ttlDays = 30,
    ) {
    }

    public function find(string $repo, string $fingerprint): ?int
    {
        $cached = $this->cache->get($this->key($repo, $fingerprint));

        if (is_int($cached) && $cached > 0) {
            return $cached;
        }

        $found = $this->github->findIssueByMarker($repo, IssueBody::marker($fingerprint));

        if ($found !== null) {
            // Promote it, so the next occurrence in this burst does not pay for
            // another search request against a rate-limited endpoint.
            $this->remember($repo, $fingerprint, $found);
        }

        return $found;
    }

    public function remember(string $repo, string $fingerprint, int $issue): void
    {
        if ($this->ttlDays <= 0) {
            return;
        }

        $this->cache->put(
            $this->key($repo, $fingerprint),
            $issue,
            $this->ttlDays * 86400
        );
    }

    /**
     * Forget the mapping — used when an issue turns out to be closed, so the
     * next occurrence of the error opens a fresh one instead of commenting on a
     * thread nobody is reading.
     */
    public function forget(string $repo, string $fingerprint): void
    {
        $this->cache->forget($this->key($repo, $fingerprint));
    }

    private function key(string $repo, string $fingerprint): string
    {
        return self::PREFIX . $repo . ':' . $fingerprint;
    }
}
