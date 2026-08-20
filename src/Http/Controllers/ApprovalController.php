<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JeffKolez\FirstResponder\Sinks\GitHubClient;
use JeffKolez\FirstResponder\Sinks\TelegramClient;
use JeffKolez\FirstResponder\Support\ApprovalTokens;
use JeffKolez\FirstResponder\Support\Gatekeeper;
use JeffKolez\FirstResponder\Support\IssueRegistry;
use Psr\Log\LoggerInterface;

/**
 * Handles a button press on an alert.
 *
 * The point of this endpoint is that deciding what to do about an error should
 * cost one tap on a phone rather than a laptop, a VPN and twenty minutes. What
 * it does not do is act on its own: every route through here needs a person to
 * have pressed something first, and the most it will ever do is add a label.
 *
 * Authentication is Telegram's own mechanism — the secret token registered with
 * setWebhook, echoed back in a header on every delivery. Nothing here trusts
 * the body, which is why the callback carries an opaque token and not a
 * repository name: a forged payload cannot name a repository to write to,
 * because the repository is only ever read from our own cache.
 */
class ApprovalController
{
    public function __invoke(
        Request $request,
        ApprovalTokens $tokens,
        IssueRegistry $registry,
        GitHubClient $github,
        TelegramClient $telegram,
        Gatekeeper $gatekeeper,
        LoggerInterface $logger,
    ): JsonResponse {
        $config = (array) config('first-responder.approvals', []);
        $secret = (string) ($config['secret'] ?? '');

        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''))) {
            $logger->warning('first-responder: rejected an approval callback (bad or missing secret).', [
                'ip' => $request->ip(),
            ]);

            // 404 rather than 401, for the same reason as the Sentry route: an
            // unauthenticated caller learns nothing about whether this exists.
            return response()->json(['error' => 'Not Found'], 404);
        }

        $callback = (array) $request->input('callback_query', []);
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');

        if ($callbackId === '') {
            // Signed, valid, not a button press. Telegram is told 200 so it
            // does not mark the webhook as failing and start retrying.
            return response()->json(['status' => 'ignored']);
        }

        if ($data === 'fr:noop') {
            // The label left behind after a decision. Acknowledge so the button
            // does not spin, and do nothing else.
            $telegram->answerCallback($callbackId);

            return response()->json(['status' => 'ignored']);
        }

        if (preg_match('/^fr:(fix|mute):([a-f0-9]{24})$/', $data, $matches) !== 1) {
            $telegram->answerCallback($callbackId, 'That button is not one of mine.');

            return response()->json(['status' => 'ignored']);
        }

        [, $verb, $token] = $matches;

        $chatId = $callback['message']['chat']['id'] ?? null;

        if (! $this->chatIsAllowed($chatId, (array) ($config['allowed_chat_ids'] ?? []))) {
            $logger->warning('first-responder: approval callback from an unexpected chat.', [
                'chat_id' => $chatId,
            ]);

            $telegram->answerCallback($callbackId, 'Not permitted from this chat.');

            return response()->json(['status' => 'ignored']);
        }

        $payload = $tokens->resolve($token);

        if ($payload === null) {
            // Expired, or the cache was flushed. Say so plainly: the person
            // pressed the right button and deserves to know why nothing
            // happened rather than watching a spinner stop.
            $telegram->answerCallback($callbackId, 'That alert has expired. Open the issue directly.');

            return response()->json(['status' => 'expired']);
        }

        // The work is done before acknowledging, so the text on the toast is
        // the actual outcome rather than a guess. Both branches are two HTTP
        // calls at worst, well inside Telegram's patience.
        [$note, $toast] = $verb === 'fix'
            ? $this->sendToAgent($payload, $registry, $github, (array) config('first-responder.github', []))
            : $this->mute($payload, $gatekeeper, (int) ($config['mute_hours'] ?? 24));

        $telegram->answerCallback($callbackId, $toast);

        if ($note !== null && $chatId !== null && isset($callback['message']['message_id'])) {
            $telegram->replaceMarkup($chatId, (int) $callback['message']['message_id'], $note);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array{repo: string, fingerprint: string}  $payload
     * @param  array<string, mixed>  $github_config
     * @return array{0: string|null, 1: string}
     */
    private function sendToAgent(
        array $payload,
        IssueRegistry $registry,
        GitHubClient $github,
        array $github_config,
    ): array {
        // The issue is looked up now rather than carried in the token, because
        // the chat message is often rendered before the issue exists — channel
        // order is the user's choice, not ours.
        $issue = $registry->find($payload['repo'], $payload['fingerprint']);

        if ($issue === null) {
            return [null, 'No issue was filed for this one.'];
        }

        $label = (string) ($github_config['agent_label'] ?? 'agent-fix');

        if (! $github->addLabel($payload['repo'], $issue, $label)) {
            return [null, "Could not label issue #{$issue}."];
        }

        return ["Sent to the agent · #{$issue}", "Labelled #{$issue} — the agent will pick it up."];
    }

    /**
     * @param  array{repo: string, fingerprint: string}  $payload
     * @return array{0: string|null, 1: string}
     */
    private function mute(array $payload, Gatekeeper $gatekeeper, int $hours): array
    {
        $hours = max(1, $hours);

        $gatekeeper->mute($payload['fingerprint'], $hours * 60);

        return ["Muted for {$hours}h", "Quiet for {$hours}h. The issue stays open."];
    }

    /**
     * An empty allow-list means any chat, which is the right default: the
     * secret token already proves the request came from Telegram, and most
     * people have exactly one alert chat and would not thank us for making them
     * find its id before anything works.
     *
     * @param  array<int, mixed>  $allowed
     */
    private function chatIsAllowed(mixed $chatId, array $allowed): bool
    {
        if ($allowed === []) {
            return true;
        }

        return in_array((string) $chatId, array_map('strval', $allowed), true);
    }
}
