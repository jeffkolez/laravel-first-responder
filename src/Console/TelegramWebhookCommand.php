<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Console;

use Illuminate\Console\Command;
use JeffKolez\FirstResponder\Sinks\TelegramClient;

/**
 * Registers (or removes) the approval webhook with Telegram.
 *
 * This exists because it is the one setup step with no visible failure mode.
 * Everything else in the package either works or logs; a webhook that was never
 * registered just means the buttons do nothing forever, and there is nothing in
 * the application to look at. One command that says what it did is cheaper than
 * the support question.
 */
class TelegramWebhookCommand extends Command
{
    protected $signature = 'first-responder:telegram-webhook
        {--url= : Override the URL. Defaults to the approvals path on APP_URL}
        {--delete : Remove the webhook instead of setting it}';

    protected $description = 'Point Telegram at the approval route';

    public function handle(TelegramClient $telegram): int
    {
        if (! $telegram->configured()) {
            $this->components->error('No bot token. Set FIRST_RESPONDER_TELEGRAM_BOT_TOKEN.');

            return self::FAILURE;
        }

        if ($this->option('delete')) {
            $ok = $telegram->deleteWebhook();

            $this->components->{$ok ? 'info' : 'error'}(
                $ok ? 'Webhook removed. The buttons will stop working.' : 'Telegram refused. Check the log.'
            );

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $config = (array) config('first-responder.approvals', []);
        $secret = (string) ($config['secret'] ?? '');

        if ($secret === '') {
            $this->components->error('No secret. Set FIRST_RESPONDER_APPROVAL_SECRET, or the route will not exist.');

            return self::FAILURE;
        }

        $url = (string) ($this->option('url')
            ?: rtrim((string) config('app.url'), '/') . '/' . ltrim((string) ($config['path'] ?? 'first-responder/approve'), '/'));

        // Telegram will not deliver to a plain-HTTP endpoint and the failure
        // arrives as a generic refusal, so it is worth naming here.
        if (! str_starts_with($url, 'https://')) {
            $this->components->error("Telegram only delivers to https. Got: {$url}");

            return self::FAILURE;
        }

        if (! $telegram->setWebhook($url, $secret)) {
            $this->components->error('Telegram refused the webhook. Check the log for its reason.');

            return self::FAILURE;
        }

        $this->components->info('Webhook registered.');
        $this->components->twoColumnDetail('URL', $url);
        $this->components->twoColumnDetail('Updates', 'callback_query only');

        if (! ($config['enabled'] ?? false)) {
            $this->newLine();
            $this->components->warn('approvals.enabled is false, so no buttons will be attached to alerts.');
        }

        return self::SUCCESS;
    }
}
