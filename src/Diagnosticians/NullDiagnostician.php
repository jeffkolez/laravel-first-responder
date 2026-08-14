<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Diagnosticians;

use JeffKolez\FirstResponder\Contracts\Diagnostician;
use JeffKolez\FirstResponder\Support\Diagnosis;
use JeffKolez\FirstResponder\Support\Incident;

/**
 * Forms no opinion.
 *
 * The default when no driver is configured, and the recommended setting for
 * anyone who wants the alerting without sending source code to a third party.
 * Reports still go out with the type, message, location and source context,
 * which is most of the value. They just lack the prose explanation.
 *
 * Also what you bind in tests so a suite never makes a network call.
 */
final class NullDiagnostician implements Diagnostician
{
    public function diagnose(Incident $incident): ?Diagnosis
    {
        return null;
    }
}
