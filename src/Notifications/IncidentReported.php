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

    /** Enough to name the input, not enough to bury the diagnosis. */
    private const MAX_DATA_LINES = 8;

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

            if ($signature = $frames[0]->signature()) {
                $lines[] = 'in ' . self::clip($signature, 140);
            }
        }

        if ($this->incident->url !== null && $this->incident->url !== '') {
            $method = $this->incident->context['method'] ?? null;

            $lines[] = trim(((string) $method) . ' ' . $this->incident->url);
        }

        if ($this->incident->environment !== null && $this->incident->environment !== '') {
            $lines[] = 'env: ' . $this->incident->environment;
        }

        if ($data = $this->dataLines()) {
            $lines[] = '';
            $lines = array_merge($lines, $data);
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

            if ($signature = $frames[0]->signature()) {
                $mail->line('in ' . self::clip($signature, 140));
            }
        }

        if ($this->incident->url !== null && $this->incident->url !== '') {
            $method = $this->incident->context['method'] ?? null;

            $mail->line(trim(((string) $method) . ' ' . $this->incident->url));
        }

        foreach ($this->dataLines() as $line) {
            $mail->line($line);
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

/**
     * The values this request actually ran with.
     *
     * The whole reason this package exists rather than a Log::error is that
     * somebody should be able to read the alert and know what broke. "Invalid
     * date format at EventController.php:72" is not that; "Invalid date format
     * … date: banana19" is. So the input goes in the message, above the fold,
     * not only in the prompt.
     *
     * Ruthlessly capped, because this is read on a phone between other things.
     * Route parameters come first: they are the part of the request the
     * application itself picked out as meaningful, which makes them the best
     * single guess at the offending value. Forensic detail (IP, user agent,
     * referer) is deliberately left out here and kept for the issue body —
     * it answers "who did this", and the alert is about "what broke".
     *
     * @return string[]
     */
    private function dataLines(): array
    {
        $context = $this->incident->context;
        $lines = [];

        foreach ((array) ($context['route_params'] ?? []) as $key => $value) {
            $lines[] = self::pair((string) $key, $value);
        }

        foreach ((array) ($context['query'] ?? []) as $key => $value) {
            $lines[] = self::pair('?' . $key, $value);
        }

        foreach ((array) ($context['body'] ?? []) as $key => $value) {
            $lines[] = self::pair($key . ':', $value);
        }

        if (isset($context['command'])) {
            $lines[] = self::pair('command', $context['command']);
        }

        if (isset($context['user_id'])) {
            $lines[] = self::pair('user', $context['user_id']);
        }

        // Only the innermost wrapped exception. A chain printed in full pushes
        // the diagnosis off the screen, and the innermost one is the why.
        $previous = (array) ($context['previous'] ?? []);

        if ($previous !== []) {
            $lines[] = 'caused by: ' . self::clip((string) end($previous), 160);
        }

        return array_slice($lines, 0, self::MAX_DATA_LINES);
    }

    private static function pair(string $key, mixed $value): string
    {
        return $key . ': ' . self::clip(is_scalar($value) || $value === null
            ? var_export($value, true)
            : gettype($value), 120);
    }

    private static function clip(string $value, int $limit): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1) . '…' : $value;
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
