<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use JeffKolez\FirstResponder\Contracts\Diagnostician;
use JeffKolez\FirstResponder\Diagnosticians\AnthropicDiagnostician;
use JeffKolez\FirstResponder\Diagnosticians\NullDiagnostician;
use JeffKolez\FirstResponder\Diagnosticians\OpenAiDiagnostician;
use JeffKolez\FirstResponder\Http\Controllers\SentryWebhookController;
use JeffKolez\FirstResponder\Support\Gatekeeper;
use JeffKolez\FirstResponder\Support\PromptBuilder;
use JeffKolez\FirstResponder\Support\Redactor;
use JeffKolez\FirstResponder\Support\SourceExtractor;
use Psr\Log\LoggerInterface;

class FirstResponderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/first-responder.php', 'first-responder');

        $this->app->singleton(PromptBuilder::class, function (Application $app) {
            return new PromptBuilder(
                (int) $app['config']->get('first-responder.max_frames', 3),
                (int) $app['config']->get('first-responder.word_limit', 80),
            );
        });

        $this->app->singleton(Redactor::class, function (Application $app) {
            return new Redactor(
                (array) $app['config']->get('first-responder.redact_patterns', []),
                (array) $app['config']->get('first-responder.redact_literals', []),
                // Null means "read the real environment", which is the whole
                // point — the literal matcher needs the actual secret values.
                null,
                (bool) $app['config']->get('first-responder.redact_emails', true),
            );
        });

        $this->app->singleton(SourceExtractor::class, function (Application $app) {
            return new SourceExtractor(
                $app->basePath(),
                (int) $app['config']->get('first-responder.source_lines', 5),
            );
        });

        $this->app->singleton(Gatekeeper::class, function (Application $app) {
            return new Gatekeeper(
                $app->make(CacheFactory::class)->store(),
                (int) $app['config']->get('first-responder.dedupe_minutes', 60),
                (int) $app['config']->get('first-responder.max_per_hour', 20),
            );
        });

        $this->app->singleton(Diagnostician::class, fn (Application $app) => $this->makeDiagnostician($app));

        $this->app->singleton(FirstResponder::class, function (Application $app) {
            $config = (array) $app['config']->get('first-responder', []);
            $config['environment'] = $app->environment();

            return new FirstResponder(
                $app->make(Gatekeeper::class),
                $app->make(SourceExtractor::class),
                $app->make(Redactor::class),
                $app->make(\Illuminate\Contracts\Bus\Dispatcher::class),
                $config,
            );
        });

        $this->app->alias(FirstResponder::class, 'first-responder');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/first-responder.php' => $this->app->configPath('first-responder.php'),
            ], 'first-responder-config');
        }

        $this->registerSentryRoute();
    }

    /**
     * The Sentry ingest route only exists when it is switched on AND has a
     * secret. Registering an unauthenticated public endpoint because somebody
     * half-configured the package would be the worst possible default.
     */
    private function registerSentryRoute(): void
    {
        $config = (array) $this->app['config']->get('first-responder.sentry', []);

        if (! ($config['enabled'] ?? false)) {
            return;
        }

        if (($config['secret'] ?? '') === '') {
            return;
        }

        Route::middleware((array) ($config['middleware'] ?? ['api']))
            ->post(
                (string) ($config['path'] ?? 'first-responder/sentry'),
                SentryWebhookController::class
            )
            ->name('first-responder.sentry');
    }

    private function makeDiagnostician(Application $app): Diagnostician
    {
        $driver = (string) $app['config']->get('first-responder.diagnostician', 'null');
        $config = (array) $app['config']->get("first-responder.diagnosticians.{$driver}", []);
        $prompts = $app->make(PromptBuilder::class);
        $logger = $app->make(LoggerInterface::class);

        return match ($driver) {
            'openai' => new OpenAiDiagnostician(
                (string) ($config['key'] ?? ''),
                (string) ($config['model'] ?? 'gpt-4o-mini'),
                $prompts,
                $logger,
                (string) ($config['base_url'] ?? 'https://api.openai.com/v1'),
                (int) ($config['timeout'] ?? 20),
            ),
            'anthropic' => new AnthropicDiagnostician(
                (string) ($config['key'] ?? ''),
                (string) ($config['model'] ?? 'claude-haiku-4-5-20251001'),
                $prompts,
                $logger,
                (string) ($config['base_url'] ?? 'https://api.anthropic.com/v1'),
                (int) ($config['timeout'] ?? 20),
            ),
            default => new NullDiagnostician(),
        };
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            FirstResponder::class,
            Diagnostician::class,
            Gatekeeper::class,
            Redactor::class,
            SourceExtractor::class,
            PromptBuilder::class,
            'first-responder',
        ];
    }
}
