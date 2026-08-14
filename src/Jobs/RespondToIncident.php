<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use JeffKolez\FirstResponder\Contracts\Diagnostician;
use JeffKolez\FirstResponder\Notifications\IncidentReported;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\Recipients;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Works out why, then tells somebody.
 *
 * Takes a plain array rather than an Incident so the payload is trivially
 * serialisable and stays readable in a failed_jobs row. It has already been
 * redacted by FirstResponder::prepare(), so nothing sensitive is sitting in the
 * queue backend.
 */
/*
 * Note the absence of Illuminate\Foundation\Bus\Dispatchable. It would drag
 * illuminate/foundation — the entire framework skeleton — into this package's
 * dependency graph purely to provide a static ::dispatch() helper we never
 * call: FirstResponder dispatches through the Bus contract it is given.
 */
class RespondToIncident implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Two attempts. The error is already recorded elsewhere; this is the telling. */
    public int $tries = 2;

    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload)
    {
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function handle(
        Diagnostician $diagnostician,
        LoggerInterface $logger,
    ): void {
        $incident = Incident::fromArray($this->payload);

        // Contractually a Diagnostician never throws — but it is an interface
        // anyone can implement, and a third-party implementation that breaks
        // must not be able to swallow the alert.
        try {
            $diagnosis = $diagnostician->diagnose($incident);
        } catch (Throwable $e) {
            $logger->warning('first-responder: diagnostician threw; reporting without a diagnosis.', [
                'exception' => $e->getMessage(),
            ]);

            $diagnosis = null;
        }

        $config = (array) config('first-responder.notifications', []);
        $channels = (array) ($config['channels'] ?? ['mail']);

        // Shared with first-responder:test, so the command exercises exactly
        // the construction production uses rather than a lookalike.
        $notifiable = Recipients::fromRoutes((array) ($config['routes'] ?? []));

        if ($notifiable === null) {
            $logger->warning('first-responder: no notification routes configured; nothing sent.');

            return;
        }

        $notifiable->notify(new IncidentReported($incident, $diagnosis, $channels));
    }
}
