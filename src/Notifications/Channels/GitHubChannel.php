<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Notifications\Channels;

use Illuminate\Notifications\Notification;
use JeffKolez\FirstResponder\Notifications\IncidentReported;
use JeffKolez\FirstResponder\Sinks\GitHubClient;
use JeffKolez\FirstResponder\Support\Diagnosis;
use JeffKolez\FirstResponder\Support\Fingerprint;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\IssueBody;
use JeffKolez\FirstResponder\Support\IssueRegistry;
use JeffKolez\FirstResponder\Support\RepoRouter;
use Psr\Log\LoggerInterface;

/**
 * Files the incident as a GitHub issue.
 *
 * A notification channel rather than a stage in the job, which is the whole
 * trick: everything upstream — the ignore list, source enrichment, redaction,
 * fingerprinting, the dedupe window and the hourly budget — already applies,
 * because this runs downstream of all of it. The hourly cap in particular is
 * doing quiet work here. A deploy that breaks fifty things cannot open fifty
 * issues, for the same reason it cannot send fifty messages.
 *
 * Note what this is not. It does not replace the chat notification; it runs
 * alongside it. Chat is what reaches a person in a minute. An issue is where
 * the work goes, and nobody watches an issue tracker at 2am.
 */
final class GitHubChannel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly GitHubClient $github,
        private readonly IssueRegistry $registry,
        private readonly RepoRouter $router,
        private readonly array $config = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof IncidentReported) {
            return;
        }

        if (! ($this->config['enabled'] ?? false)) {
            return;
        }

        $incident = $notification->incident;
        $diagnosis = $notification->diagnosis;

        if (! $this->shouldFile($incident, $diagnosis)) {
            return;
        }

        $repo = $this->router->repoFor($incident, $this->routeFrom($notifiable, $notification));

        if ($repo === null) {
            $this->logger?->warning('first-responder: no GitHub repo resolved for this incident; nothing filed.', [
                'type' => $incident->type,
            ]);

            return;
        }

        $fingerprint = Fingerprint::for($incident);

        // Checked before the token, deliberately. A dry run is exactly what you
        // do BEFORE making a token — to see what a day's errors would produce
        // without creating anything, or granting anything write access.
        if ($this->config['dry_run'] ?? false) {
            $this->logger?->info('first-responder: would file a GitHub issue.', [
                'repo' => $repo,
                'title' => IssueBody::title($incident),
                'labels' => (array) ($this->config['labels'] ?? []),
                'fingerprint' => $fingerprint,
                // Whether this would open a new issue or comment on an existing
                // one cannot be known without asking GitHub, which a dry run
                // does not do.
            ]);

            return;
        }

        if (! $this->github->configured()) {
            $this->logger?->warning('first-responder: GitHub is enabled but no token is set; nothing filed.');

            return;
        }

        if ($this->commentedOnExisting($repo, $fingerprint, $incident)) {
            return;
        }

        $number = $this->github->createIssue(
            $repo,
            IssueBody::title($incident),
            IssueBody::render($incident, $diagnosis, $fingerprint),
            (array) ($this->config['labels'] ?? []),
        );

        if ($number !== null) {
            $this->registry->remember($repo, $fingerprint, $number);
            $this->rememberUrl($repo, $fingerprint, $number);
        }
    }

    /**
     * Built rather than read back from the API response.
     *
     * The create call returns an html_url, but taking it would mean widening
     * GitHubClient::createIssue's return type for one string that is entirely
     * derivable. The shape of a GitHub issue URL is not going to move.
     */
    private function rememberUrl(string $repo, string $fingerprint, int $number): void
    {
        $this->registry->rememberUrl(
            $fingerprint,
            'https://github.com/' . $repo . '/issues/' . $number
        );
    }

    /**
     * @return bool  True when an existing issue absorbed this occurrence.
     */
    private function commentedOnExisting(string $repo, string $fingerprint, Incident $incident): bool
    {
        $existing = $this->registry->find($repo, $fingerprint);

        if ($existing === null) {
            return false;
        }

        // A closed issue means somebody fixed this. If it is happening again,
        // that is a regression and deserves its own issue with its own history,
        // not a comment on a thread nobody is subscribed to any more.
        if ($this->github->issueIsOpen($repo, $existing) !== true) {
            $this->registry->forget($repo, $fingerprint);

            return false;
        }

        $note = 'Seen again — ' . gmdate('Y-m-d H:i') . ' UTC';

        if (($incident->url ?? '') !== '') {
            $note .= "\n\n`" . $incident->url . '`';
        }

        $this->github->comment($repo, $existing, $note);

        // Refreshed on every recurrence, so the link survives a cache flush as
        // long as the error keeps happening.
        $this->rememberUrl($repo, $fingerprint, $existing);

        return true;
    }

    private function shouldFile(Incident $incident, ?Diagnosis $diagnosis): bool
    {
        // Note the asymmetry: an explicitly low-confidence diagnosis is
        // withheld, but a missing one is not. "I looked and I am unsure" is a
        // reason not to open a ticket; "no diagnostician is configured" is not,
        // and treating them the same would mean an installation without AI
        // silently files nothing at all.
        if (($this->config['only_confident'] ?? true)
            && $diagnosis !== null
            && ! $diagnosis->isEmpty()
            && ! $diagnosis->confident) {
            return false;
        }

        $frames = $incident->significantFrames(1);

        // A crash entirely inside vendor code is real, and it is still worth a
        // chat message, but it is not something anyone is going to fix in this
        // repository.
        return $frames !== [] && $frames[0]->inApp;
    }

    /**
     * A notification route, if one was configured, acts as the default repo.
     * The extension map in RepoRouter still wins over it, because that is the
     * more specific statement.
     */
    private function routeFrom(object $notifiable, Notification $notification): ?string
    {
        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return null;
        }

        $route = $notifiable->routeNotificationFor('github', $notification);

        return is_string($route) && trim($route) !== '' ? trim($route) : null;
    }
}
