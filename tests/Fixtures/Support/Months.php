<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Fixtures\Support;

class Months
{
    public static function number(string $name): ?int
    {
        return strlen($name) < 3 ? null : 1;
    }
}
