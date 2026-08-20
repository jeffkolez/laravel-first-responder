<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Feature;

use Illuminate\Support\Facades\Http;
use JeffKolez\FirstResponder\Support\ApprovalTokens;
use JeffKolez\FirstResponder\Support\Gatekeeper;
use JeffKolez\FirstResponder\Support\IssueRegistry;
use JeffKolez\FirstResponder\Tests\TestCase;

/**
 * The approval route writes to somebody's repository, so the interesting tests
 * are the ones about what it refuses to do.
 */
class ApprovalRouteTest extends TestCase
{
    private const SECRET = 'test-approval-secret';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('first-responder.approvals.enabled', true);
        $app['config']->set('first-responder.approvals.secret', self::SECRET);
        $app['config']->set('first-responder.approvals.bot_token', 'bot-token');
        $app['config']->set('first-responder.approvals.middleware', []);
        $app['config']->set('first-responder.github.enabled', true);
        $app['config']->set('first-responder.github.token', 'gh-token');
        $app['config']->set('first-responder.github.repo', 'acme/api');
    }

    private function press(string $data, ?string $secret = self::SECRET, array $extra = [])
    {
        $headers = $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret];

        return $this->postJson('/first-responder/approve', [
            'update_id' => 1,
            'callback_query' => array_merge([
                'id' => 'cb-1',
                'from' => ['id' => 99],
                'message' => ['message_id' => 5, 'chat' => ['id' => -100]],
                'data' => $data,
            ], $extra),
        ], $headers);
    }

    private function mintToken(string $fingerprint = 'abc123'): string
    {
        return (string) app(ApprovalTokens::class)->mint('acme/api', $fingerprint);
    }

    public function test_it_refuses_a_request_with_no_secret(): void
    {
        Http::fake();

        $this->press('fr:fix:' . $this->mintToken(), null)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_it_refuses_a_wrong_secret(): void
    {
        Http::fake();

        $this->press('fr:fix:' . $this->mintToken(), 'not-the-secret')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_fix_labels_the_issue_for_that_fingerprint(): void
    {
        app(IssueRegistry::class)->remember('acme/api', 'abc123', 41);

        Http::fake([
            'api.github.com/*' => Http::response([], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->press('fr:fix:' . $this->mintToken('abc123'))->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/repos/acme/api/issues/41/labels'
            && in_array('agent-fix', (array) $request['labels'], true));
    }

    public function test_fix_says_so_when_no_issue_was_filed(): void
    {
        Http::fake([
            // No registry entry and the search finds nothing.
            'api.github.com/search/issues*' => Http::response(['items' => []], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->press('fr:fix:' . $this->mintToken('unfiled'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery')
            && str_contains((string) $request['text'], 'No issue'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/labels'));
    }

    public function test_mute_silences_that_fingerprint(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->press('fr:mute:' . $this->mintToken('quiet-me'))->assertOk();

        // Gatekeeper::claim returns false for a fingerprint already claimed, so
        // a successful mute is observable as the next report being suppressed.
        $this->assertFalse(app(Gatekeeper::class)->claim('quiet-me'));
    }

    public function test_an_unknown_token_is_reported_rather_than_ignored(): void
    {
        Http::fake();

        $this->press('fr:fix:' . str_repeat('a', 24))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery')
            && str_contains((string) $request['text'], 'expired'));
    }

    public function test_malformed_callback_data_touches_nothing(): void
    {
        Http::fake();

        $this->press('fr:fix:../../etc/passwd')->assertOk();
        $this->press('fr:delete:' . $this->mintToken())->assertOk();
        $this->press('garbage')->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.github.com'));
    }

    public function test_the_placeholder_button_does_nothing(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->press('fr:noop')->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.github.com'));
    }

    public function test_a_non_button_update_is_acknowledged_and_dropped(): void
    {
        Http::fake();

        // Telegram must get a 200 or it marks the webhook as failing and
        // starts retrying.
        $this->postJson('/first-responder/approve', ['update_id' => 2], [
            'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ])->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_chat_outside_the_allow_list_is_refused(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        config()->set('first-responder.approvals.allowed_chat_ids', [-999]);

        $this->press('fr:fix:' . $this->mintToken())->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.github.com'));
    }
}
