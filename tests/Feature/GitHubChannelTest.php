<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Feature;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use JeffKolez\FirstResponder\Notifications\IncidentReported;
use JeffKolez\FirstResponder\Support\Diagnosis;
use JeffKolez\FirstResponder\Support\Fingerprint;
use JeffKolez\FirstResponder\Support\Frame;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\IssueBody;
use JeffKolez\FirstResponder\Tests\TestCase;

/**
 * The GitHub channel end to end, with the API faked.
 *
 * The expensive mistakes this guards against are all about volume: filing an
 * issue when it should have commented, filing into the wrong repository, or
 * filing at all when the feature is switched off.
 */
class GitHubChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->reconfigure([
            'first-responder.notifications.channels' => ['github'],
            'first-responder.notifications.routes' => ['github' => 'acme/api'],
            'first-responder.github.enabled' => true,
            'first-responder.github.token' => 'test-token',
            'first-responder.github.repo' => 'acme/api',
        ]);
    }

    private function incident(string $file = 'app/Foo.php'): Incident
    {
        return new Incident(
            'RuntimeException',
            'boom',
            [new Frame($file, 12, null, true, [], '$x = 1;', [])],
            null,
            'production',
        );
    }

    private function notify(Incident $incident, ?Diagnosis $diagnosis = null): void
    {
        (new AnonymousNotifiable())
            ->route('github', config('first-responder.notifications.routes.github'))
            ->notify(new IncidentReported($incident, $diagnosis, ['github']));
    }

    /** No existing issue, so search finds nothing and one is created. */
    private function fakeEmptySearchThenCreate(int $number = 42): void
    {
        Http::fake([
            'api.github.com/search/issues*' => Http::response(['items' => []], 200),
            'api.github.com/repos/*/issues' => Http::response(['number' => $number], 201),
        ]);
    }

    public function test_it_files_an_issue(): void
    {
        $this->fakeEmptySearchThenCreate();

        $this->notify($this->incident());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/repos/acme/api/issues')
                && $request->method() === 'POST'
                && str_contains((string) $request['body'], 'fr-fingerprint-')
                && in_array('bug', (array) $request['labels'], true);
        });
    }

    public function test_it_sends_the_api_version_and_a_bearer_token(): void
    {
        $this->fakeEmptySearchThenCreate();

        $this->notify($this->incident());

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
    }

    public function test_it_files_nothing_when_disabled(): void
    {
        Http::fake();
        $this->reconfigure(['first-responder.github.enabled' => false]);

        $this->notify($this->incident());

        Http::assertNothingSent();
    }

    public function test_it_files_nothing_without_a_token(): void
    {
        Http::fake();
        $this->reconfigure(['first-responder.github.token' => '']);

        $this->notify($this->incident());

        Http::assertNothingSent();
    }

    public function test_dry_run_files_nothing(): void
    {
        Http::fake();
        $this->reconfigure(['first-responder.github.dry_run' => true]);

        $this->notify($this->incident());

        Http::assertNothingSent();
    }

    /**
     * The second occurrence must comment, not file again. This is the whole
     * reason IssueRegistry exists: Gatekeeper's dedupe window is measured in
     * minutes and would let the same bug become a new issue tomorrow.
     */
    public function test_a_known_fingerprint_comments_instead_of_filing_again(): void
    {
        $incident = $this->incident();

        // Order matters: Http::fake takes the first matching pattern, so the
        // comments URL has to be listed before the bare issue URL or the
        // "is it open" lookup would match the comments stub and read no state.
        Http::fake([
            'api.github.com/search/issues*' => Http::response(['items' => []], 200),
            'api.github.com/repos/acme/api/issues/7/comments' => Http::response(['id' => 1], 201),
            'api.github.com/repos/acme/api/issues/7' => Http::response(['state' => 'open'], 200),
            'api.github.com/repos/acme/api/issues' => Http::response(['number' => 7], 201),
        ]);

        $this->notify($incident);
        $this->notify($incident);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/issues/7/comments')
            && str_contains((string) $request['body'], 'Seen again'));

        // Exactly one create, ever.
        $creates = 0;
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/repos/acme/api/issues')) {
                $creates++;
            }
        }
        $this->assertSame(1, $creates);
    }

    /**
     * A closed issue means somebody fixed it. A recurrence is a regression and
     * gets its own issue rather than a comment on a thread nobody watches.
     */
    public function test_a_closed_issue_is_not_reused(): void
    {
        $incident = $this->incident();

        Http::fake([
            'api.github.com/search/issues*' => Http::response(['items' => []], 200),
            'api.github.com/repos/acme/api/issues/7' => Http::response(['state' => 'closed'], 200),
            'api.github.com/repos/acme/api/issues' => Http::response(['number' => 7], 201),
        ]);

        $this->notify($incident);
        $this->notify($incident);

        $creates = 0;
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/repos/acme/api/issues')) {
                $creates++;
            }
        }
        $this->assertSame(2, $creates);
    }

    public function test_the_extension_map_routes_to_the_frontend_repo(): void
    {
        $this->reconfigure(['first-responder.github.repo_map' => ['ts,tsx' => 'acme/web']]);
        $this->fakeEmptySearchThenCreate();

        $this->notify($this->incident('src/Home.tsx'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/repos/acme/web/issues'));
    }

    public function test_an_unconfident_diagnosis_is_not_filed(): void
    {
        Http::fake();

        $this->notify($this->incident(), Diagnosis::make('Could be caching, could be anything.', false));

        Http::assertNothingSent();
    }

    /**
     * A missing diagnosis is not the same as an unsure one. Without this, an
     * installation using the null diagnostician would file nothing at all and
     * look broken.
     */
    public function test_a_missing_diagnosis_is_still_filed(): void
    {
        $this->fakeEmptySearchThenCreate();

        $this->notify($this->incident(), null);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/repos/acme/api/issues')
            && $request->method() === 'POST');
    }

    public function test_a_vendor_only_crash_is_not_filed(): void
    {
        Http::fake();

        $incident = new Incident(
            'RuntimeException',
            'boom',
            [new Frame('vendor/laravel/framework/src/Foo.php', 9, null, false)],
            null,
            'production',
        );

        $this->notify($incident);

        Http::assertNothingSent();
    }

    public function test_the_search_looks_for_the_marker_of_this_fingerprint(): void
    {
        $incident = $this->incident();
        $this->fakeEmptySearchThenCreate();

        $this->notify($incident);

        $marker = IssueBody::marker(Fingerprint::for($incident));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/search/issues')
            && str_contains(urldecode($request->url()), $marker));
    }
}
