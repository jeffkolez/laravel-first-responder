<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Sinks;

use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Bot API calls needed to answer a button press.
 *
 * Deliberately not a delivery channel. Sending the alert is still the job of
 * whichever community package the user installed; this only handles the replies
 * that a webhook has to make, which no notification channel package covers
 * because they are all one-way.
 *
 * Nothing throws. A button that fails to acknowledge is a spinner that does not
 * stop — annoying, not an outage.
 */
final class TelegramClient
{
    public function __construct(
        private readonly string $botToken,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $baseUrl = 'https://api.telegram.org',
        private readonly int $timeout = 10,
    ) {
    }

    public function configured(): bool
    {
        return trim($this->botToken) !== '';
    }

    /**
     * Stop the spinner on the pressed button.
     *
     * Telegram shows a loading state on the button until this is called and
     * gives up after a few seconds, so it wants calling early and
     * unconditionally — including on the failure paths, where the text is the
     * only way the person learns anything went wrong.
     */
    public function answerCallback(string $callbackId, string $text = ''): bool
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            // 200 is the Bot API's limit for this field.
            'text' => mb_substr($text, 0, 200),
        ]);
    }

    /**
     * Replace the buttons with a line of text recording what was chosen.
     *
     * Buttons that stay tappable after they have been used invite a second
     * press, and a chat history full of live buttons from three weeks ago is
     * worse than useless — you cannot tell by looking which ones you already
     * dealt with.
     */
    public function replaceMarkup(int|string $chatId, int $messageId, string $note): bool
    {
        return $this->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode([
                'inline_keyboard' => [[['text' => $note, 'callback_data' => 'fr:noop']]],
            ]),
        ]);
    }

    public function setWebhook(string $url, string $secret): bool
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            // Without this Telegram sends every message in the chat, and the
            // route would spend its time ignoring the team's conversation.
            'allowed_updates' => json_encode(['callback_query']),
        ]);
    }

    public function deleteWebhook(): bool
    {
        return $this->call('deleteWebhook', []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(string $method, array $payload): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->retry(1, 300, throw: false)
                ->asForm()
                ->post(
                    rtrim($this->baseUrl, '/') . '/bot' . $this->botToken . '/' . $method,
                    $payload
                );

            if (! $response->successful()) {
                $this->logger?->warning('first-responder: Telegram call failed.', [
                    'method' => $method,
                    'status' => $response->status(),
                    'description' => (string) ($response->json('description') ?? ''),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->logger?->warning('first-responder: Telegram call errored.', [
                'method' => $method,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
