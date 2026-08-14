<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Decides whether an incident is worth spending money and attention on.
 *
 * Two independent limits, because they fail in different directions:
 *
 * DEDUPE answers "have we already said this?". A bad deploy throws the same
 * exception thousands of times a minute. Reporting each one buries the signal
 * in the noise at exactly the moment somebody is trying to read the channel.
 *
 * BUDGET answers "have we said too much, full stop?". Dedupe alone does not
 * bound anything: a deploy that breaks fifty DIFFERENT things produces fifty
 * distinct fingerprints, every one of them novel, every one of them an AI call.
 * The cap is what stops an incident becoming an invoice.
 *
 * Both use Cache::add, which is atomic. This matters more than it looks: a
 * spike is precisely the moment several queue workers process the same
 * fingerprint concurrently, and a read-then-write check would let all of them
 * through.
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
     * Claims the slot as a side effect — calling twice returns false the second
     * time. That is the point, but it means this must be called exactly once
     * per incident, at the moment you commit to reporting it.
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
     * Increments on success. Uses a fixed hourly window rather than a rolling
     * one: a rolling window needs a sorted set or a timestamp list, and the
     * extra machinery buys nothing here — the goal is a ceiling on spend, not
     * a precise rate.
     */
    public function withinBudget(): bool
    {
        if ($this->maxPerHour <= 0) {
            return true;
        }

        $key = self::BUDGET_PREFIX . date('YmdH');

        // add() both creates the counter and tells us whether we created it, so
        // the first call of the hour needs no separate existence check. The TTL
        // is set once, here, and never extended by later increments — otherwise
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

    /** How many reports have gone out this hour. */
    public function usedThisHour(): int
    {
        return (int) $this->cache->get(self::BUDGET_PREFIX . date('YmdH'), 0);
    }
}
