<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Unit;

use JeffKolez\FirstResponder\Support\Diagnosis;
use JeffKolez\FirstResponder\Support\Frame;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\IssueBody;
use PHPUnit\Framework\TestCase;

final class IssueBodyTest extends TestCase
{
    private function incident(array $overrides = []): Incident
    {
        return new Incident(
            $overrides['type'] ?? 'RuntimeException',
            $overrides['message'] ?? 'Undefined array key "slug"',
            $overrides['frames'] ?? [new Frame('app/Services/Builder.php', 214, null, true, ['$a = 1;'], '$b = $a["slug"];', ['return $b;'])],
            $overrides['url'] ?? 'https://example.com/killers/x',
            $overrides['environment'] ?? 'production',
            $overrides['release'] ?? 'a1b2c3d',
            'error',
            $overrides['context'] ?? ['method' => 'GET'],
            $overrides['externalId'] ?? null,
            $overrides['externalUrl'] ?? 'https://sentry.io/issues/1',
        );
    }

    public function test_the_marker_contains_no_colon(): void
    {
        // GitHub's issue search reads a colon as a qualifier separator even
        // inside a quoted phrase, so an "ext:4171" fingerprint would make the
        // lookup silently return nothing.
        $marker = IssueBody::marker('ext:4171');

        $this->assertStringNotContainsString(':', $marker);
        $this->assertSame('fr-fingerprint-ext-4171', $marker);
    }

    public function test_the_marker_is_stable_for_a_plain_hash(): void
    {
        $this->assertSame('fr-fingerprint-abc123', IssueBody::marker('abc123'));
    }

    public function test_the_body_carries_the_marker_the_registry_searches_for(): void
    {
        $body = IssueBody::render($this->incident(), null, 'abc123');

        $this->assertStringContainsString('<!-- ' . IssueBody::marker('abc123') . ' -->', $body);
    }

    public function test_it_includes_location_environment_release_and_request(): void
    {
        $body = IssueBody::render($this->incident(), null, 'abc123');

        $this->assertStringContainsString('app/Services/Builder.php:214', $body);
        $this->assertStringContainsString('production', $body);
        $this->assertStringContainsString('release `a1b2c3d`', $body);
        $this->assertStringContainsString('GET https://example.com/killers/x', $body);
    }

    public function test_a_confident_diagnosis_is_headed_differently_from_an_unsure_one(): void
    {
        $confident = IssueBody::render($this->incident(), Diagnosis::make('The key is optional.', true), 'a');
        $unsure = IssueBody::render($this->incident(), Diagnosis::make('Maybe caching.', false), 'a');

        $this->assertStringContainsString('### Likely cause', $confident);
        $this->assertStringContainsString('low confidence', $unsure);
    }

    public function test_source_is_fenced_with_a_language_hint(): void
    {
        $body = IssueBody::render($this->incident(), null, 'a');

        $this->assertStringContainsString("```php\n", $body);
        $this->assertStringContainsString('$b = $a["slug"];', $body);
    }

    public function test_source_containing_a_fence_does_not_break_out_of_the_block(): void
    {
        $frame = new Frame('app/Doc.php', 3, null, true, ['/** ```php'], '$x = 1;', ['``` */']);

        $body = IssueBody::render($this->incident(['frames' => [$frame]]), null, 'a');

        // The opening fence must be longer than anything inside it, or the rest
        // of the file spills into the issue as prose.
        $this->assertStringContainsString("````php\n", $body);
    }

    public function test_it_refuses_a_non_http_external_url(): void
    {
        // externalUrl arrives from a webhook payload, so it is attacker-shaped.
        $body = IssueBody::render(
            $this->incident(['externalUrl' => 'javascript:alert(1)']),
            null,
            'a'
        );

        $this->assertStringNotContainsString('javascript:', $body);
    }

    public function test_it_keeps_an_http_external_url(): void
    {
        $body = IssueBody::render($this->incident(), null, 'a');

        $this->assertStringContainsString('https://sentry.io/issues/1', $body);
    }

    public function test_the_title_is_capped_and_single_line(): void
    {
        $title = IssueBody::title($this->incident(['message' => "line one\nline two " . str_repeat('x', 400)]));

        $this->assertStringNotContainsString("\n", $title);
        $this->assertLessThanOrEqual(200, mb_strlen($title));
    }

    public function test_the_title_falls_back_when_there_is_nothing_to_say(): void
    {
        $incident = new Incident('', '', []);

        $this->assertSame('Unhandled error', IssueBody::title($incident));
    }

    public function test_markdown_in_the_message_cannot_restyle_the_issue(): void
    {
        $body = IssueBody::render($this->incident(['message' => 'bad **bold** value']), null, 'a');

        $this->assertStringContainsString('\*\*bold\*\*', $body);
    }
}
