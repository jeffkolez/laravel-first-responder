<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use JeffKolez\FirstResponder\Contracts\Diagnostician;
use JeffKolez\FirstResponder\Diagnosticians\NullDiagnostician;
use JeffKolez\FirstResponder\FirstResponder;
use JeffKolez\FirstResponder\Jobs\RespondToIncident;
use JeffKolez\FirstResponder\Notifications\IncidentReported;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\Recipients;
use RuntimeException;
use Throwable;

/**
 * Prove the whole pipeline works, without waiting for production to break.
 *
 * Why it ignores the gates
 * ------------------------
 * `environments`, the dedupe window and the hourly budget all exist to stop
 * reports being sent. A test command subject to them would refuse to do
 * anything the second time you ran it, and would refuse the first time on any
 * machine that isn't production, which is every machine you would be
 * configuring on. So it bypasses all three, and prints that it has done so
 * instead of leaving you to wonder why nothing happened.
 *
 * Everything else is the real path: a real thrown exception, the real source
 * extractor, the real redactor, the real diagnostician, the real notification,
 * through the real channel.
 */
class TestCommand extends Command
{
    protected $signature = 'first-responder:test
        {--no-ai : Skip the diagnosis call, so the test costs nothing}
        {--dry : Show what would be sent without sending it}
        {--queue : Dispatch through the queue instead of running inline, to prove a worker is consuming it}';

    protected $description = 'Send a test incident through the full First Responder pipeline';

    public function handle(FirstResponder $responder, Diagnostician $diagnostician): int
    {
        $this->line('');
        $this->components->info('First Responder pipeline test');

        $config = (array) config('first-responder.notifications', []);
        $channels = (array) ($config['channels'] ?? []);
        $routes = (array) ($config['routes'] ?? []);

        if (! $this->reportConfiguration($channels, $routes, $diagnostician)) {
            return self::FAILURE;
        }

        // ── A real exception, so the source extractor has real work to do ──
        $incident = $responder->prepare(
            Incident::fromThrowable($this->provokeException(), ['test' => true])
        );

        $frames = $incident->significantFrames(1);

        $this->components->twoColumnDetail(
            'Incident',
            $incident->title()
        );
        $this->components->twoColumnDetail(
            'Location',
            $frames === [] ? '<fg=red>no frames</>' : $frames[0]->location()
        );
        $this->components->twoColumnDetail(
            'Source context',
            ($frames !== [] && $frames[0]->context() !== '')
                ? '<fg=green>read from disk</>'
                : '<fg=yellow>none. Check source_lines and file permissions.</>'
        );

        $this->reportCapture();

        /*
         * --queue exists because everything else in this command runs inline,
         * and inline is not how production works. A real error is prepared in
         * the request, queued, and finished by a worker. If the worker is dead,
         * or watching a different queue, every check below still passes and no
         * alert arrives. This is the only way to test that link without
         * breaking something for real.
         */
        if ($this->option('queue')) {
            return $this->dispatchThroughQueue($incident);
        }

        // ── Diagnosis ──────────────────────────────────────────────────────
        $diagnosis = null;

        if ($this->option('no-ai')) {
            $this->components->twoColumnDetail('Diagnosis', '<fg=gray>skipped (--no-ai)</>');
        } elseif ($diagnostician instanceof NullDiagnostician) {
            $this->components->twoColumnDetail('Diagnosis', '<fg=gray>driver is "null", no AI call</>');
        } else {
            $diagnosis = $this->attemptDiagnosis($diagnostician, $incident);
        }

        // ── Render ─────────────────────────────────────────────────────────
        $notification = new IncidentReported($incident, $diagnosis, $channels);

        $this->line('');
        $this->line('<fg=gray>── message ──────────────────────────────────</>');
        $this->line($notification->toText());
        $this->line('<fg=gray>─────────────────────────────────────────────</>');
        $this->line('');

        if ($this->option('dry')) {
            $this->components->warn('Dry run. Nothing was sent.');

            return self::SUCCESS;
        }

        // ── Deliver ────────────────────────────────────────────────────────
        $notifiable = Recipients::fromRoutes($routes);

        try {
            $notifiable->notify($notification);
        } catch (Throwable $e) {
            $this->components->error('Delivery threw: ' . $e->getMessage());
            $this->line('  <fg=gray>The channel package is usually the thing to check here.</>');

            return self::FAILURE;
        }

        $this->components->info('Sent. If it does not arrive, the transport accepted it but did not deliver it.');

        foreach ($channels as $channel) {
            $this->line('  <fg=gray>' . $this->shortName($channel) . ' → ' . $this->describeRoute($routes[$channel] ?? null) . '</>');
        }

        $this->line('');
        $this->line('  <fg=gray>Telegram: a bot cannot message a person who has never messaged it.</>');
        $this->line('  <fg=gray>If nothing arrives, send the bot /start and try again.</>');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Hand the real job to the real queue and stop.
     *
     * Nothing is reported back because there is nothing to report: a separate
     * process finishes the work. If the message arrives, the worker is alive
     * and watching the right queue. If it does not, the job is sitting in the
     * queue table or Horizon is down, and either way the failure is visible
     * somewhere you can look.
     */
    private function dispatchThroughQueue(Incident $incident): int
    {
        $job = new RespondToIncident($incident->toArray());

        if ($queue = config('first-responder.queue')) {
            $job->onQueue($queue);
        }

        if ($connection = config('first-responder.queue_connection')) {
            $job->onConnection($connection);
        }

        app(Dispatcher::class)->dispatch($job);

        $this->line('');
        $this->components->info('Job dispatched. A worker must pick it up for anything to arrive.');
        $this->components->twoColumnDetail('Connection', (string) (config('first-responder.queue_connection') ?: config('queue.default')));
        $this->components->twoColumnDetail('Queue', (string) (config('first-responder.queue') ?: 'default'));
        $this->line('');
        $this->line('  <fg=gray>No message within a few seconds means the worker is the problem,</>');
        $this->line('  <fg=gray>not the configuration. Check Horizon, or that a worker is watching</>');
        $this->line('  <fg=gray>the queue named above. Run without --queue to bypass the worker.</>');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * @param  string[]  $channels
     * @param  array<string, mixed>  $routes
     */
    private function reportConfiguration(array $channels, array $routes, Diagnostician $diagnostician): bool
    {
        $env = app()->environment();
        $gated = (array) config('first-responder.environments', []);

        $this->components->twoColumnDetail('Environment', $env);

        if ($gated !== [] && ! in_array($env, $gated, true)) {
            $this->components->twoColumnDetail(
                'Environment gate',
                '<fg=yellow>bypassed for this test. Real errors here would not be reported.</>'
            );
        }

        $this->components->twoColumnDetail(
            'Enabled',
            config('first-responder.enabled', true) ? '<fg=green>yes</>' : '<fg=yellow>no. Real errors are not reported.</>'
        );

        $this->components->twoColumnDetail('Diagnostician', $this->shortName($diagnostician::class));
        $this->components->twoColumnDetail('Redaction', config('first-responder.redact', true) ? '<fg=green>on</>' : '<fg=red>OFF</>');

        if ($channels === []) {
            $this->line('');
            $this->components->error('No notification channels configured.');
            $this->line('  <fg=gray>Set first-responder.notifications.channels, e.g. [\'mail\'] or a channel class.</>');

            return false;
        }

        $unrouted = Recipients::channelsMissingRoutes($channels, $routes);

        if ($unrouted !== []) {
            $this->line('');
            $this->components->error('These channels have no route, so nothing would be delivered:');

            foreach ($unrouted as $channel) {
                $this->line('  <fg=red>•</> ' . $this->shortName($channel));
            }

            $this->line('  <fg=gray>Add a matching key under first-responder.notifications.routes.</>');
            $this->line('  <fg=gray>This is silent at runtime: the channel is called and returns.</>');

            return false;
        }

        return true;
    }

    private function attemptDiagnosis(Diagnostician $diagnostician, Incident $incident): ?object
    {
        $started = microtime(true);
        $diagnosis = $diagnostician->diagnose($incident);
        $ms = (int) ((microtime(true) - $started) * 1000);

        if ($diagnosis === null) {
            $this->components->twoColumnDetail(
                'Diagnosis',
                "<fg=yellow>none returned ({$ms}ms)</>"
            );
            $this->line('  <fg=gray>The driver returns null rather than throwing, by contract. Usual causes:</>');
            $this->line('  <fg=gray>a missing or rejected API key, or the provider being unreachable.</>');
            $this->line('  <fg=gray>Check the log. The driver writes a warning with the status code.</>');

            return null;
        }

        $this->components->twoColumnDetail(
            'Diagnosis',
            sprintf(
                '<fg=green>%s (%dms%s)</>',
                $diagnosis->model ?? 'ok',
                $ms,
                $diagnosis->confident ? '' : ', low confidence'
            )
        );

        return $diagnosis;
    }

    /**
     * Throw and catch inside the package so the incident carries a real stack
     * trace pointing at a real file. That gives the source extractor something
     * to do, so this exercises it too.
     */
    private function provokeException(): Throwable
    {
        try {
            throw new RuntimeException(
                'First Responder test incident. Nothing is broken.'
            );
        } catch (Throwable $e) {
            return $e;
        }
    }

/**
     * Whether the alert will carry the values that caused the error.
     *
     * Worth its own line because both ways of failing here are silent. Capture
     * switched off produces a perfectly healthy-looking alert with no data in
     * it; `zend.exception_ignore_args` produces a stack trace with the
     * arguments quietly missing, and the setting is On in php.ini-production,
     * so most people have it without ever choosing it.
     *
     * This command runs on the command line, where there is no request to
     * capture — so what it reports is whether capture is configured, not what
     * a real report would collect.
     */
    private function reportCapture(): void
    {
        $this->components->twoColumnDetail(
            'Request capture',
            config('first-responder.capture.enabled', true)
                ? '<fg=green>on</> <fg=gray>(url, route, parameters, query'
                    . (config('first-responder.capture.body', false) ? ', body' : '')
                    . ')</>'
                : '<fg=yellow>off. Alerts will not say what data caused the error.</>'
        );

        $ignoring = filter_var(
            ini_get('zend.exception_ignore_args'),
            FILTER_VALIDATE_BOOL
        );

        $this->components->twoColumnDetail(
            'Trace arguments',
            $ignoring
                ? '<fg=gray>stripped by PHP (zend.exception_ignore_args=On, the production default)</>'
                : '<fg=green>available</>'
        );
    }

    private function shortName(string $class): string
    {
        return str_contains($class, '\\') ? class_basename($class) : $class;
    }

    private function describeRoute(mixed $route): string
    {
        return is_scalar($route) ? (string) $route : gettype($route);
    }
}
