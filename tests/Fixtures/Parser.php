<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Fixtures;

use JeffKolez\FirstResponder\Tests\Fixtures\Support\Months;

/**
 * A stand-in for the shape that motivated CodeContext: the pattern that
 * produces the bad value, the call that rejects it, and the throw are three
 * different places, and only one of them is the frame.
 */
class Parser
{
    public function parse(string $date): int
    {
        // Unanchored on purpose — this is the bug the model has to find.
        if (! preg_match('/([a-zA-Z]+)(\d+)/', $date, $matches)) {
            throw new \RuntimeException('no match');
        }

        $filler = 1;
        $filler++;
        $filler++;

        $month = Months::number($matches[1]);

        if ($month === null) {
            throw new \RuntimeException('not a month');
        }

        return $month;
    }

    private function unused(): string
    {
        return 'never called from the failing line';
    }
}
