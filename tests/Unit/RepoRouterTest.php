<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Unit;

use JeffKolez\FirstResponder\Support\Frame;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\RepoRouter;
use PHPUnit\Framework\TestCase;

final class RepoRouterTest extends TestCase
{
    private function incident(string $file): Incident
    {
        return new Incident('RuntimeException', 'boom', [new Frame($file, 12, null, true)]);
    }

    public function test_it_falls_back_to_the_default_repo(): void
    {
        $router = new RepoRouter('acme/api');

        $this->assertSame('acme/api', $router->repoFor($this->incident('app/Foo.php')));
    }

    public function test_the_extension_map_wins_over_the_default(): void
    {
        $router = new RepoRouter('acme/api', ['ts,tsx,js' => 'acme/web']);

        $this->assertSame('acme/web', $router->repoFor($this->incident('src/pages/Home.tsx')));
        $this->assertSame('acme/api', $router->repoFor($this->incident('app/Foo.php')));
    }

    public function test_the_map_wins_over_a_per_send_default_too(): void
    {
        // The route supplies a default; the map is the more specific statement
        // and must still take precedence.
        $router = new RepoRouter(null, ['ts' => 'acme/web']);

        $this->assertSame('acme/web', $router->repoFor($this->incident('a.ts'), 'acme/other'));
        $this->assertSame('acme/other', $router->repoFor($this->incident('a.php'), 'acme/other'));
    }

    public function test_extensions_are_matched_case_insensitively_and_tolerate_spacing(): void
    {
        $router = new RepoRouter(null, [' TS , .tsx ' => 'acme/web']);

        $this->assertSame('acme/web', $router->repoFor($this->incident('a.TS')));
        $this->assertSame('acme/web', $router->repoFor($this->incident('b.tsx')));
    }

    public function test_it_ignores_a_query_string_on_a_bundle_url(): void
    {
        // Frontend frames arrive as URLs, often cache-busted. Without stripping
        // the query the extension reads as "js?v=9" and never matches.
        $router = new RepoRouter('acme/api', ['js' => 'acme/web']);

        $incident = $this->incident('https://example.com/_next/static/chunk.js?v=9');

        $this->assertSame('acme/web', $router->repoFor($incident));
    }

    public function test_it_returns_null_when_nothing_is_configured(): void
    {
        $this->assertNull((new RepoRouter(null))->repoFor($this->incident('app/Foo.php')));
        $this->assertNull((new RepoRouter('  '))->repoFor($this->incident('app/Foo.php')));
    }

    public function test_blank_repos_in_the_map_are_skipped(): void
    {
        // env() returning null for an unset frontend repo must not shadow the
        // default with an empty string.
        $router = new RepoRouter('acme/api', ['ts' => '']);

        $this->assertSame('acme/api', $router->repoFor($this->incident('a.ts')));
    }

    public function test_it_survives_an_incident_with_no_frames(): void
    {
        $incident = new Incident('RuntimeException', 'boom', []);

        $this->assertSame('acme/api', (new RepoRouter('acme/api'))->repoFor($incident));
    }
}
