<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use JeffKolez\FirstResponder\Support\Diagnosis;
use JeffKolez\FirstResponder\Support\Fingerprint;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\IssueRegistry;
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
 * Note that `github` is a workflow sink rather than a chat channel — it needs
 * no route, because it works out its repository for itself.
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

        if ($issue = $this->issueUrl()) {
            $lines[] = '';
            $lines[] = $issue;
        }

        return implode("\n", $lines);
    }

    /**
     * The issue this incident was filed as, if it has been.
     *
     * A link is the whole point of filing one: the alert tells you something
     * broke, and the issue is where you go to do anything about it — read the
     * source, label it for an agent, or close it.
     *
     * Looked up from the cache rather than passed in, because Laravel clones
     * the notification for each channel, so the GitHub channel's copy is not
     * the one the chat channel renders. See IssueRegistry::rememberUrl for the
     * ordering caveat: with the chat channel listed first, the very first alert
     * for a new error has no link yet.
     */
    private function issueUrl(): ?string
    {
        try {
            if (! (bool) config('first-responder.github.enabled', false)) {
                return null;
            }

            return app(IssueRegistry::class)->urlFor(Fingerprint::for($this->incident));
        } catch (Throwable) {
            // Never let decorating an alert cost you the alert.
            return null;
        }
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
