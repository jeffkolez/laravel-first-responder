<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use JeffKolez\FirstResponder\Support\ApprovalTokens;
use JeffKolez\FirstResponder\Support\Diagnosis;
use JeffKolez\FirstResponder\Support\Fingerprint;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\RepoRouter;
use Throwable;

/**
 * The report itself.
 *
 * A plain Laravel Notification, not a bundle of chat channel drivers. That is
 * the delivery strategy: every channel package that already exists (Telegram,
 * Slack, Discord, Teams, ntfy) works with this for free, and this package
 * maintains none of them.
 *
 * The one channel it does ship is `github`, and the exception proves the rule:
 * every chat destination has a maintained community package, and nothing
 * anywhere turns a redacted, deduplicated, diagnosed incident into an issue.
 *
 * `toArray()` carries the structured incident, so a custom channel can format
 * it however it likes without parsing the prose back apart.
 */
class IncidentReported extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Incident $incident,
        public readonly ?Diagnosis $diagnosis = null,
        /** @var string[] */
        public readonly array $channels = ['mail'],
    ) {
    }

    /**
     * @return string[]
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /**
     * Plain text, for the many channels that take a string.
     *
     * Kept free of markdown deliberately: Telegram's MarkdownV2 and Slack's
     * mrkdwn disagree about escaping, and a stray underscore in a class name is
     * enough to make one of them reject the whole message. Channel packages can
     * format from toArray() if they want richness.
     */
    public function toText(): string
    {
        $lines = [
            $this->severityMark() . ' ' . $this->incident->title(),
        ];

        $frames = $this->incident->significantFrames(1);

        if ($frames !== []) {
            $lines[] = 'at ' . $frames[0]->location();
        }

        if ($this->incident->url !== null && $this->incident->url !== '') {
            $lines[] = 'on ' . $this->incident->url;
        }

        if ($this->incident->environment !== null && $this->incident->environment !== '') {
            $lines[] = 'env: ' . $this->incident->environment;
        }

        if ($this->diagnosis !== null && ! $this->diagnosis->isEmpty()) {
            $lines[] = '';
            $lines[] = $this->diagnosis->confident
                ? 'Likely cause'
                : 'Possible cause (low confidence)';
            $lines[] = $this->diagnosis->summary;
        }

        if ($this->incident->externalUrl !== null && $this->incident->externalUrl !== '') {
            $lines[] = '';
            $lines[] = $this->incident->externalUrl;
        }

        return implode("\n", $lines);
    }

    /**
     * The Telegram message, with the approval buttons when they are switched on.
     *
     * This is the one place the package knows a specific chat service exists,
     * and it earns that by being the only way to put a button in front of
     * somebody. Delivery is still laravel-notification-channels/telegram's job;
     * we only hand it a richer object than a string.
     *
     * Written defensively on purpose. That package is a `suggest`, not a
     * dependency, so its API is not pinned by anything here — every call is
     * guarded, and any surprise degrades to the plain message that has always
     * worked rather than throwing inside a notification about an error.
     *
     * No return type, deliberately: it is a TelegramMessage when the package is
     * installed and a string when it is not.
     */
    public function toTelegram(object $notifiable)
    {
        $text = $this->toText();

        $class = '\NotificationChannels\Telegram\TelegramMessage';

        if (! class_exists($class) || ! method_exists($class, 'create')) {
            return $text;
        }

        $message = $class::create($text);

        // That package defaults to Markdown parsing, and Telegram rejects the
        // entire message if the markers do not balance. A class name with an
        // underscore in it is enough. Nothing here is markdown, so turn it off.
        if (method_exists($message, 'options')) {
            $message->options(['parse_mode' => null]);
        }

        return $this->withApprovalButtons($message);
    }

    /**
     * Mint a token and attach the buttons, or return the message untouched.
     *
     * The token points at a fingerprint rather than an issue number, because
     * this message is usually rendered before the issue is filed — channel
     * order is the user's configuration, not something to depend on.
     */
    private function withApprovalButtons(object $message): object
    {
        if (! method_exists($message, 'buttonWithCallback')) {
            return $message;
        }

        try {
            $tokens = app(ApprovalTokens::class);

            if (! $tokens->enabled()) {
                return $message;
            }

            $repo = app(RepoRouter::class)->repoFor($this->incident);

            if ($repo === null) {
                return $message;
            }

            $token = $tokens->mint($repo, Fingerprint::for($this->incident));

            if ($token === null) {
                return $message;
            }

            $message->buttonWithCallback('Fix it', "fr:fix:{$token}");
            $message->buttonWithCallback('Mute 24h', "fr:mute:{$token}");
        } catch (Throwable) {
            // An alert with no buttons is a degraded alert. An exception thrown
            // while formatting one is a lost alert, during an incident.
            return $message;
        }

        return $message;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject('[' . ($this->incident->environment ?? 'production') . '] ' . $this->incident->title())
            ->line($this->incident->title());

        $frames = $this->incident->significantFrames(1);

        if ($frames !== []) {
            $mail->line('at ' . $frames[0]->location());
        }

        if ($this->diagnosis !== null && ! $this->diagnosis->isEmpty()) {
            $mail->line($this->diagnosis->confident ? 'Likely cause:' : 'Possible cause (low confidence):');
            $mail->line($this->diagnosis->summary);
        }

        if ($this->incident->externalUrl !== null && $this->incident->externalUrl !== '') {
            $mail->action('View the error', $this->incident->externalUrl);
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'incident' => $this->incident->toArray(),
            'diagnosis' => $this->diagnosis === null ? null : [
                'summary' => $this->diagnosis->summary,
                'confident' => $this->diagnosis->confident,
                'model' => $this->diagnosis->model,
            ],
            'text' => $this->toText(),
        ];
    }

    /**
     * Channels that take a raw string get one without any per-channel classes.
     *
     * Named generically because several community channel packages look for a
     * `toTelegram`/`toSlack` and fall back to `__toString()` on the
     * notification. Providing this keeps the common case zero-config.
     */
    public function __toString(): string
    {
        return $this->toText();
    }

    private function severityMark(): string
    {
        return match (strtolower($this->incident->level)) {
            'fatal', 'critical' => '🚨',
            'warning' => '⚠️',
            'info', 'debug' => 'ℹ️',
            default => '🔴',
        };
    }
}
