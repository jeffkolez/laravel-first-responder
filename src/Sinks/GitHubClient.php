<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Sinks;

use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The small corner of the GitHub REST API this package needs.
 *
 * Three methods, no SDK. Octokit-equivalents for PHP bring a dependency tree
 * and a release cadence out of all proportion to "create an issue", and this
 * package's whole position on vendor SDKs is that it would rather own a thin
 * interface than inherit somebody else's churn.
 *
 * Nothing here throws. An error tracker that takes the site down when GitHub
 * has an incident is worse than one that quietly stops filing issues, and the
 * chat notification — the part that actually pages a human — has already gone
 * out by the time any of this runs.
 */
final class GitHubClient
{
    private const API_VERSION = '2022-11-28';

    public function __construct(
        private readonly string $token,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $baseUrl = 'https://api.github.com',
        private readonly int $timeout = 15,
    ) {
    }

    public function configured(): bool
    {
        return trim($this->token) !== '';
    }

    /**
     * @param  string[]  $labels
     * @return int|null  The new issue number, or null if it could not be filed.
     */
    public function createIssue(string $repo, string $title, string $body, array $labels = []): ?int
    {
        // Labels that do not exist yet are created by GitHub on the fly with a
        // default colour, so there is no need to provision them first.
        $response = $this->request('post', "/repos/{$repo}/issues", [
            'title' => $title,
            'body' => $body,
            'labels' => array_values(array_filter($labels)),
        ]);

        if ($response === null) {
            return null;
        }

        $number = $response['number'] ?? null;

        return is_int($number) ? $number : null;
    }

    /**
     * Is this issue still open?
     *
     * Returns null when the answer is unknown — the request failed, the token
     * lost access, the issue was deleted. Callers must treat null as "not
     * confidently open" rather than as false, because the two lead to different
     * actions and guessing wrong means either a duplicate issue or a comment
     * into the void.
     */
    public function issueIsOpen(string $repo, int $issue): ?bool
    {
        $response = $this->request('get', "/repos/{$repo}/issues/{$issue}", []);

        if ($response === null) {
            return null;
        }

        $state = $response['state'] ?? null;

        return is_string($state) ? $state === 'open' : null;
    }

    public function comment(string $repo, int $issue, string $body): bool
    {
        return $this->request('post', "/repos/{$repo}/issues/{$issue}/comments", [
            'body' => $body,
        ]) !== null;
    }

    /**
     * Find an existing issue carrying this marker.
     *
     * A backstop, not the primary lookup. GitHub's search index is populated
     * asynchronously and lags by seconds to minutes, which is precisely the
     * window in which a burst of the same error arrives. IssueRegistry checks a
     * cache first for that reason; this catches the case where the cache has
     * been evicted or flushed.
     *
     * Open issues only. A closed one means somebody fixed it, and if the error
     * is back it deserves a fresh issue rather than a comment on a dead thread.
     */
    public function findIssueByMarker(string $repo, string $marker): ?int
    {
        $response = $this->request('get', '/search/issues', [
            'q' => sprintf('repo:%s is:issue is:open in:body "%s"', $repo, $marker),
            'per_page' => 1,
            // Required since GitHub split issue search off from the legacy
            // combined endpoint; harmlessly ignored by older deployments.
            'advanced_search' => 'true',
        ]);

        if ($response === null) {
            return null;
        }

        $number = $response['items'][0]['number'] ?? null;

        return is_int($number) ? $number : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, array $payload): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => self::API_VERSION,
                'User-Agent' => 'laravel-first-responder',
            ])
                ->timeout($this->timeout)
                // One retry, and never throwing: a failed issue is a degraded
                // report, not an incident of its own.
                ->retry(1, 500, throw: false)
                ->{$method}(rtrim($this->baseUrl, '/') . $path, $payload);

            if (! $response->successful()) {
                $this->logger?->warning('first-responder: GitHub request failed.', [
                    'path' => $path,
                    'status' => $response->status(),
                    // GitHub explains refusals (missing scope, archived repo,
                    // secondary rate limit) in the body, and without it every
                    // failure looks identical in the log.
                    'message' => (string) ($response->json('message') ?? ''),
                ]);

                return null;
            }

            return (array) $response->json();
        } catch (Throwable $e) {
            $this->logger?->warning('first-responder: GitHub request errored.', [
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
