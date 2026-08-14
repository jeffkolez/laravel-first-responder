<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder;

use Illuminate\Contracts\Bus\Dispatcher;
use JeffKolez\FirstResponder\Jobs\RespondToIncident;
use JeffKolez\FirstResponder\Support\Fingerprint;
use JeffKolez\FirstResponder\Support\Gatekeeper;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\Redactor;
use JeffKolez\FirstResponder\Support\SourceExtractor;
use Throwable;

/**
 * The front door. Everything that reports an error comes through here.
 *
 * ORDER OF OPERATIONS, AND WHY IT IS THIS ORDER
 * ---------------------------------------------
 * 1. filter    — is this worth reporting at all?
 * 2. enrich    — attach source context FROM DISK, now, while the deployed code
 *                still matches the code that threw. Do it in the queued job
 *                instead and a deploy between the throw and the job running
 *                gives you the wrong lines, confidently presented.
 * 3. REDACT    — before anything is serialised. The queue payload lands in
 *                Redis or a database table, and secrets sitting in a jobs table
 *                are a leak whether or not a model ever sees them. Redacting
 *                after dequeue would protect the model and not the queue.
 * 4. gatekeep  — claim the fingerprint and spend budget BEFORE dispatching, so
 *                a storm never even creates the jobs.
 * 5. dispatch  — the slow part (diagnosis, delivery) happens off the request.
 */
final class FirstResponder
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly Gatekeeper $gatekeeper,
        private readonly SourceExtractor $source,
        private readonly Redactor $redactor,
        private readonly Dispatcher $bus,
        private readonly array $config,
    ) {
    }

    /**
     * Report a throwable or an already-normalised incident.
     *
     * @param  array<string, mixed>  $context
     * @return bool  Whether it was accepted for reporting.
     */
    public function report(Throwable|Incident $subject, array $context = []): bool
    {
        if (! ($this->config['enabled'] ?? true)) {
            return false;
        }

        $incident = $subject instanceof Incident
            ? $subject
            : Incident::fromThrowable($subject, $context);

        if (! $this->shouldReport($incident)) {
            return false;
        }

        $incident = $this->prepare($incident);

        // Claim first, budget second. If the fingerprint is a duplicate we
        // return before touching the budget — otherwise a single hot error
        // would burn the hourly allowance on reports nobody ever sees.
        if (! $this->gatekeeper->claim(Fingerprint::for($incident))) {
            return false;
        }

        if (! $this->gatekeeper->withinBudget()) {
            return false;
        }

        $job = new RespondToIncident($incident->toArray());

        if ($queue = ($this->config['queue'] ?? null)) {
            $job->onQueue($queue);
        }

        if ($connection = ($this->config['queue_connection'] ?? null)) {
            $job->onConnection($connection);
        }

        $this->bus->dispatch($job);

        return true;
    }

    /**
     * Enrich then scrub. Public so a custom source (a webhook receiver, say)
     * can run the same treatment without going through report().
     */
    public function prepare(Incident $incident): Incident
    {
        $frames = $this->source->fillAll($incident->frames);

        $enriched = new Incident(
            $incident->type,
            $incident->message,
            $frames,
            $incident->url,
            $incident->environment ?? ($this->config['environment'] ?? null),
            $incident->release,
            $incident->level,
            $incident->context,
            $incident->externalId,
            $incident->externalUrl,
        );

        return ($this->config['redact'] ?? true)
            ? $this->redactor->redactIncident($enriched)
            : $enriched;
    }

    private function shouldReport(Incident $incident): bool
    {
        $environments = (array) ($this->config['environments'] ?? []);
        $current = $this->config['environment'] ?? null;

        // An empty list means "everywhere" — the least surprising reading, and
        // it keeps local experimentation working without extra configuration.
        if ($environments !== [] && $current !== null && ! in_array($current, $environments, true)) {
            return false;
        }

        foreach ((array) ($this->config['ignore'] ?? []) as $ignored) {
            // is_a with the string flag matches subclasses too, which is what
            // people expect when they ignore HttpException and get all of them.
            if ($incident->type === $ignored || is_a($incident->type, $ignored, true)) {
                return false;
            }
        }

        return true;
    }
}
