<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Unit;

use JeffKolez\FirstResponder\Sources\SentryPayload;
use JeffKolez\FirstResponder\Support\Diagnosis;
use JeffKolez\FirstResponder\Support\Fingerprint;
use JeffKolez\FirstResponder\Support\Frame;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\PromptBuilder;
use JeffKolez\FirstResponder\Support\SourceExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CoreTest extends TestCase
{
    private function frame(): Frame
    {
        return new Frame('app/Http/Controllers/X.php', 42, 'show', true, ['$a = 1;'], 'return $a->b;', ['}']);
    }

    // ── Frames and incidents ────────────────────────────────────────────────

    public function test_vendor_frames_are_dropped_so_the_report_names_your_code(): void
    {
        $incident = new Incident('TypeError', 'null', [
            new Frame('vendor/laravel/Router.php', 10, 'dispatch', false),
            $this->frame(),
        ]);

        $frames = $incident->significantFrames();

        $this->assertCount(1, $frames);
        $this->assertSame('app/Http/Controllers/X.php', $frames[0]->file);
    }

    /** A crash entirely inside vendor code should still report a location. */
    public function test_it_falls_back_to_all_frames_when_none_are_in_app(): void
    {
        $incident = new Incident('E', 'm', [new Frame('vendor/a.php', 1, null, false)]);

        $this->assertCount(1, $incident->significantFrames());
    }

    public function test_source_context_assembles_in_order(): void
    {
        $this->assertSame("\$a = 1;\nreturn \$a->b;\n}", $this->frame()->context());
    }

    public function test_an_incident_survives_the_queue_boundary(): void
    {
        $original = new Incident('TypeError', 'null', [$this->frame()], 'https://a.test', 'production');
        $restored = Incident::fromArray($original->toArray());

        $this->assertSame($original->title(), $restored->title());
        $this->assertSame($original->url, $restored->url);
        $this->assertSame(
            $original->significantFrames()[0]->context(),
            $restored->significantFrames()[0]->context()
        );
    }

    public function test_it_builds_an_incident_from_a_throwable(): void
    {
        $incident = Incident::fromThrowable(new RuntimeException('boom'));

        $this->assertSame('RuntimeException', $incident->type);
        $this->assertSame('RuntimeException: boom', $incident->title());
        $this->assertStringContainsString('CoreTest.php', $incident->significantFrames()[0]->file);
    }

    // ── Fingerprinting ──────────────────────────────────────────────────────

    /**
     * The whole reason fingerprints are not hashes of the message. These two
     * are one bug — a missing 404 guard — and hashing the message would report
     * it as two, then two hundred, defeating the throttle entirely.
     */
    public function test_variable_data_in_the_message_does_not_fragment_the_fingerprint(): void
    {
        $a = new Incident('ModelNotFound', 'No results for model [Killer] 4171', [$this->frame()]);
        $b = new Incident('ModelNotFound', 'No results for model [Killer] 9022', [$this->frame()]);

        $this->assertSame(Fingerprint::for($a), Fingerprint::for($b));
    }

    public function test_the_same_class_from_a_different_line_is_a_different_bug(): void
    {
        $a = new Incident('TypeError', 'x', [$this->frame()]);
        $b = new Incident('TypeError', 'x', [new Frame('app/Other.php', 7, null, true)]);

        $this->assertNotSame(Fingerprint::for($a), Fingerprint::for($b));
    }

    /** Matching upstream grouping keeps the two systems telling one story. */
    public function test_an_upstream_issue_id_wins(): void
    {
        $incident = new Incident('E', 'm', [], null, null, null, 'error', [], '99');

        $this->assertSame('ext:99', Fingerprint::for($incident));
    }

    // ── Source extraction ───────────────────────────────────────────────────

    public function test_it_reads_source_from_disk_inside_the_project(): void
    {
        $extractor = new SourceExtractor(dirname(__DIR__, 2), 2);
        $filled = $extractor->fill(new Frame(__FILE__, 20, null, true));

        $this->assertNotSame('', $filled->context());
    }

    /**
     * Frame paths can originate from a webhook, making them externally
     * influenced. Without this guard, a forged frame becomes an arbitrary file
     * read whose contents are then helpfully posted to a chat channel.
     */
    public function test_it_refuses_paths_outside_the_project_root(): void
    {
        $extractor = new SourceExtractor(dirname(__DIR__, 2), 2);

        $this->assertSame('', $extractor->fill(new Frame('/etc/passwd', 1, null, true))->context());
        $this->assertSame('', $extractor->fill(
            new Frame(dirname(__DIR__, 2) . '/../../../../etc/passwd', 1, null, true)
        )->context());
    }

    /**
     * Sentry's context is a snapshot of the code that actually ran. The file on
     * disk may have been redeployed since, so overwriting would be wrong as
     * well as wasteful.
     */
    public function test_it_never_overwrites_context_that_already_exists(): void
    {
        $extractor = new SourceExtractor(dirname(__DIR__, 2), 2);
        $frame = $this->frame();

        $this->assertSame($frame->context(), $extractor->fill($frame)->context());
    }

    public function test_a_line_number_past_the_end_of_the_file_is_handled(): void
    {
        $extractor = new SourceExtractor(dirname(__DIR__, 2), 2);

        $this->assertSame('', $extractor->fill(new Frame(__FILE__, 999999, null, true))->context());
    }

    // ── Prompt ──────────────────────────────────────────────────────────────

    public function test_the_prompt_carries_what_the_model_needs(): void
    {
        $prompt = (new PromptBuilder(2, 80))->user(new Incident(
            'TypeError',
            'null given',
            [$this->frame(), new Frame('app/B.php', 20, null, true), new Frame('app/C.php', 30, null, true)],
            'https://site.test/p/1',
            'production',
        ));

        $this->assertStringContainsString('TypeError', $prompt);
        $this->assertStringContainsString('return $a->b;', $prompt);
        $this->assertStringContainsString('site.test', $prompt);
        $this->assertStringContainsString('80 words', $prompt);
        $this->assertStringContainsString('UNSURE:', $prompt);
        // max_frames = 2, so the third frame must not appear.
        $this->assertStringNotContainsString('app/C.php', $prompt);
    }

    public function test_oversized_context_is_dropped_rather_than_truncated_mid_json(): void
    {
        $incident = new Incident('E', 'm', [], null, null, null, 'error', ['blob' => str_repeat('x', 5000)]);

        $this->assertStringNotContainsString('CONTEXT:', (new PromptBuilder())->user($incident));
    }

    public function test_it_interprets_the_unsure_convention(): void
    {
        $builder = new PromptBuilder();

        $this->assertSame(['need the query log', false], $builder->interpret('UNSURE: need the query log'));
        $this->assertSame(['lowercase', false], $builder->interpret('unsure: lowercase'));
        $this->assertSame(['A cause.', true], $builder->interpret('  A cause.  '));
    }

    // ── Diagnosis ───────────────────────────────────────────────────────────

    public function test_a_runaway_model_response_cannot_flood_a_channel(): void
    {
        $this->assertSame(
            Diagnosis::MAX_SUMMARY_LENGTH,
            mb_strlen(Diagnosis::make(str_repeat('x', 5000))->summary)
        );
    }

    public function test_whitespace_is_collapsed(): void
    {
        $this->assertSame('a b', Diagnosis::make("a\n\n   b")->summary);
    }

    // ── Sentry normalisation ────────────────────────────────────────────────

    /**
     * Sentry orders frames outermost-first, the opposite of a PHP throwable.
     * Miss the reverse and every report leads with a bootstrap file.
     */
    public function test_sentry_frames_are_reversed_so_the_innermost_leads(): void
    {
        $incident = SentryPayload::toIncident(['data' => ['event' => [
            'issue_id' => '1',
            'exception' => ['values' => [[
                'type' => 'ReferenceError',
                'value' => 'heck is not defined',
                'stacktrace' => ['frames' => [
                    ['filename' => 'vendor/boot.php', 'lineno' => 1, 'in_app' => false],
                    ['filename' => 'app/Real.php', 'lineno' => 42, 'in_app' => true],
                ]],
            ]]],
        ]]]);

        $this->assertSame('app/Real.php', $incident?->significantFrames(1)[0]->file);
        $this->assertSame('ReferenceError', $incident?->type);
        $this->assertSame('ext:1', Fingerprint::for($incident));
    }

    public function test_sentry_tag_pairs_are_flattened(): void
    {
        $incident = SentryPayload::toIncident(['data' => ['event' => [
            'issue_id' => '1',
            'tags' => [['browser', 'Chrome 75']],
        ]]]);

        $this->assertSame('Chrome 75', $incident?->context['tags']['browser']);
    }

    public function test_a_body_without_an_event_is_rejected(): void
    {
        $this->assertNull(SentryPayload::toIncident(['data' => []]));
    }

    public function test_an_event_without_an_exception_block_still_normalises(): void
    {
        $incident = SentryPayload::toIncident(['data' => ['event' => [
            'issue_id' => '9',
            'title' => 'Bare message',
        ]]]);

        $this->assertSame('Bare message', $incident?->message);
    }
}
