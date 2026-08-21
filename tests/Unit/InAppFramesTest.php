<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Unit;

use JeffKolez\FirstResponder\Support\Frame;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\SourceExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Telling the application apart from its dependencies.
 *
 * This decides the single most visible line in every report — the location the
 * alert leads with, and the location the fingerprint is built from. Get it
 * wrong and every native exception is attributed to whichever framework file
 * happened to throw, which is true of almost every Laravel exception and tells
 * nobody anything.
 *
 * PHP's trace carries no in_app flag, so Incident::fromThrowable optimistically
 * marks everything true. SourceExtractor is the only object that knows where
 * the project root is, so the correction happens there.
 */
final class InAppFramesTest extends TestCase
{
    private function extractor(): SourceExtractor
    {
        return new SourceExtractor(sys_get_temp_dir(), 2);
    }

    private function frame(string $file): Frame
    {
        return new Frame($file, 10, null, true);
    }

    public function test_a_vendor_frame_is_not_in_app(): void
    {
        $root = realpath(sys_get_temp_dir());

        $frame = $this->extractor()->fill($this->frame($root . '/vendor/laravel/framework/src/Foo.php'));

        $this->assertFalse($frame->inApp);
    }

    public function test_an_application_frame_stays_in_app(): void
    {
        $root = realpath(sys_get_temp_dir());

        $frame = $this->extractor()->fill($this->frame($root . '/app/Services/Builder.php'));

        $this->assertTrue($frame->inApp);
    }

    /**
     * Sentry sends real in_app flags. A frame it has already called foreign
     * must never be promoted back, or its judgement is silently overridden by
     * a path heuristic that knows less.
     */
    public function test_it_only_ever_downgrades(): void
    {
        $frame = new Frame('/somewhere/app/Foo.php', 3, null, false);

        $this->assertFalse($this->extractor()->fill($frame)->inApp);
    }

    public function test_a_project_directory_named_vendor_something_is_not_a_dependency(): void
    {
        $extractor = new SourceExtractor('/nonexistent-root', 2);

        $this->assertTrue($extractor->fill($this->frame('/srv/vendor-app/app/Foo.php'))->inApp);
        $this->assertFalse($extractor->fill($this->frame('/srv/app/vendor/pkg/Foo.php'))->inApp);
    }

    /**
     * The payoff: the report leads with the caller's own code rather than the
     * framework file that happened to throw.
     */
    public function test_the_report_leads_with_your_code_not_the_framework(): void
    {
        $root = realpath(sys_get_temp_dir());
        $extractor = $this->extractor();

        // The shape of a real Eloquent BadMethodCallException: thrown inside
        // the framework, caused by a line in the application.
        $incident = new Incident('BadMethodCallException', 'Call to undefined method', [
            new Frame($root . '/vendor/laravel/framework/src/Model.php', 1, null, true),
            new Frame($root . '/app/Http/Controllers/ProfileController.php', 42, null, true),
        ]);

        $corrected = new Incident(
            $incident->type,
            $incident->message,
            $extractor->fillAll($incident->frames),
        );

        $leading = $corrected->significantFrames(1)[0];

        $this->assertStringContainsString('ProfileController.php', $leading->file);
    }

    /**
     * A crash entirely inside dependencies must still report a location.
     * Incident::significantFrames falls back to the unfiltered list, and a less
     * useful report beats one with no location in it at all.
     */
    public function test_an_all_vendor_trace_still_reports_something(): void
    {
        $root = realpath(sys_get_temp_dir());
        $extractor = $this->extractor();

        $incident = new Incident('RuntimeException', 'boom', [
            new Frame($root . '/vendor/a/Foo.php', 1, null, true),
            new Frame($root . '/vendor/b/Bar.php', 2, null, true),
        ]);

        $corrected = new Incident($incident->type, $incident->message, $extractor->fillAll($incident->frames));

        $this->assertNotEmpty($corrected->significantFrames(1));
    }
}
