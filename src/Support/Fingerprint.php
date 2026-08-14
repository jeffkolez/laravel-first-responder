<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

/**
 * A stable identity for "this same thing going wrong again".
 *
 * Why not hash the message
 * ------------------------
 * Exception messages contain variable data, and hashing them fragments one bug
 * into thousands of distinct alerts:
 *
 *   "No query results for model [App\Models\Killer] 4171"
 *   "No query results for model [App\Models\Killer] 9022"
 *
 * That is one bug (a missing 404 guard) and message-hashing reports it as two,
 * then two hundred. The fingerprint is what the throttle keys on, so getting
 * this wrong does not just make the reports untidy. It defeats the throttle,
 * which leaves the AI spend from a bad deploy unbounded.
 *
 * So the identity is the exception class plus the innermost in-app location.
 * Same class thrown from the same line is the same bug; the same class thrown
 * from somewhere else is not.
 *
 * If Sentry (or another upstream) already grouped the error, its issue id is
 * used instead. It has far more signal to group on than we do, and matching its
 * grouping keeps the two systems telling the same story.
 */
final class Fingerprint
{
    public static function for(Incident $incident): string
    {
        if ($incident->externalId !== null && $incident->externalId !== '') {
            return 'ext:' . $incident->externalId;
        }

        $frames = $incident->significantFrames(1);
        $location = $frames === [] ? 'unknown' : $frames[0]->location();

        return substr(hash('sha256', $incident->type . '|' . $location), 0, 32);
    }
}
