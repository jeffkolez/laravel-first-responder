<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Decides whether an incident is worth spending money and attention on.
 *
 * Two independent limits, because they fail in different directions:
 *
 * Dedupe stops us repeating ourselves. A bad deploy throws the same exception
 * thousands of times a minute, and reporting each one buries the signal in the
 * noise at the moment somebody is trying to read the channel.
 *
 * Budget caps the number of reports, and so the spend. Dedupe alone bounds
 * nothing: a deploy that breaks fifty different things produces fifty distinct
 * fingerprints, every one of them novel, every one of them an AI call.
 *
 * Both use Cache::add, which is atomic. This matters more than it looks: a
 * spike is the moment several queue workers process the same fingerprint
 * concurrently, and a read-then-write check would let all of them through.
 */
final class Gatekeeper
{
    private const DEDUPE_PREFIX = 'first-responder:seen:';

    private const BUDGET_PREFIX = 'first-responder:budget:';

    public function __construct(
        private readonly Cache $cache,
        private readonly int $dedupeMinutes = 60,
        private readonly int $maxPerHour = 20,
    ) {
    }

    /**
     * First sighting of this fingerprint in the dedupe window?
     *
     * Claims the slot as a side effect: calling twice returns false the second
     * time. That is intended, but it means this must be called exactly once per
     * incident, at the moment you commit to reporting it.
     */
    public function claim(string $fingerprint): bool
    {
        if ($this->dedupeMinutes <= 0) {
            return true;
        }

        return $this->cache->add(
            self::DEDUPE_PREFIX . $fingerprint,
            true,
            $this->dedupeMinutes * 60
        );
    }

    /**
     * Is there budget left this hour?
     *
     * Increments on success. The window is a fixed hour, not a rolling one: a
     * rolling window needs a sorted set or a timestamp list, and the extra
     * machinery buys nothing here. The goal is a ceiling on spend, not a
     * precise rate.
     */
    public function withinBudget(): bool
    {
        if ($this->maxPerHour <= 0) {
            return true;
        }

        $key = self::BUDGET_PREFIX . date('YmdH');

        // add() both creates the counter and tells us whether we created it, so
        // the first call of the hour needs no separate existence check. The TTL
        // is set once, here, and never extended by later increments. Otherwise
        // a steady trickle of errors would keep pushing the window forward and
        // the counter would never reset.
        if ($this->cache->add($key, 1, 3600)) {
            return true;
        }

        $used = (int) $this->cache->get($key, 0);

        if ($used >= $this->maxPerHour) {
            return false;
        }

        $this->cache->increment($key);

        return true;
    }

    /**
     * Hold one fingerprint quiet for longer than the usual window.
     *
     * For "I know about this, stop telling me" — a known-broken third party, or
     * something already being worked on. Uses put() rather than add() because
     * the fingerprint has by definition just been claimed by the report that
     * prompted the request, so add() would find the key present and do nothing.
     *
     * Deliberately not persisted anywhere but the cache. A mute that survived a
     * cache flush would be a mute you could forget you had set, and silence you
     * cannot explain is worse than noise.
     */
    public function mute(string $fingerprint, int $minutes): void
    {
        if ($minutes <= 0) {
            return;
        }

        $this->cache->put(self::DEDUPE_PREFIX . $fingerprint, true, $minutes * 60);
    }

    /** How many reports have gone out this hour. */
    public function usedThisHour(): int
    {
        return (int) $this->cache->get(self::BUDGET_PREFIX . date('YmdH'), 0);
    }
}
