<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Facades;

use Illuminate\Support\Facades\Facade;
use JeffKolez\FirstResponder\Support\Incident;
use Throwable;

/**
 * @method static bool report(Throwable|Incident $subject, array $context = [])
 * @method static Incident prepare(Incident $incident)
 *
 * @see \JeffKolez\FirstResponder\FirstResponder
 */
class FirstResponder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'first-responder';
    }
}
