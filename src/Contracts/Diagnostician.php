<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Contracts;

use JeffKolez\FirstResponder\Support\Diagnosis;
use JeffKolez\FirstResponder\Support\Incident;

/**
 * Something that can look at an incident and say what probably went wrong.
 *
 * Kept to one method, and deliberately NOT tied to any vendor SDK. The AI
 * library landscape churns fast — the official laravel/ai was pre-1.0 and had
 * already swapped out its own backend twice at the time of writing — so this
 * package depends on an interface it owns rather than a moving target. Bring
 * whatever client you like; implement this and bind it.
 *
 * Implementations MUST NOT throw. A diagnostician that fails should return null
 * and let the report go out without a diagnosis: losing the explanation
 * degrades the message, but losing the alert loses the outage.
 */
interface Diagnostician
{
    /**
     * @return Diagnosis|null  null when no opinion could be formed.
     */
    public function diagnose(Incident $incident): ?Diagnosis;
}
