<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use JeffKolez\FirstResponder\Console\TestCommand;
use JeffKolez\FirstResponder\Contracts\Diagnostician;
use JeffKolez\FirstResponder\Diagnosticians\AnthropicDiagnostician;
use JeffKolez\FirstResponder\Diagnosticians\NullDiagnostician;
use JeffKolez\FirstResponder\Diagnosticians\OpenAiDiagnostician;
use JeffKolez\FirstResponder\Http\Controllers\SentryWebhookController;
use JeffKolez\FirstResponder\Notifications\Channels\GitHubChannel;
use JeffKolez\FirstResponder\Sinks\GitHubClient;
use JeffKolez\FirstResponder\Support\CodeContext;
use JeffKolez\FirstResponder\Support\Gatekeeper;
use JeffKolez\FirstResponder\Support\IssueRegistry;
use JeffKolez\FirstResponder\Support\PromptBuilder;
use JeffKolez\FirstResponder\Support\Redactor;
use JeffKolez\FirstResponder\Support\RepoRouter;
use JeffKolez\FirstResponder\Support\RequestContext;
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
                // Null means "read the real environment". The literal matcher
                // needs the actual secret values.
                null,
                (bool) $app['config']->get('first-responder.redact_emails', true),
            );
        });

        $this->app->singleton(RequestContext::class, function (Application $app) {
            return new RequestContext(
                $app,
                (array) $app['config']->get('first-responder.capture', []),
            );
        });

        $this->app->singleton(SourceExtractor::class, function (Application $app) {
            return new SourceExtractor(
                $app->basePath(),
                (int) $app['config']->get('first-responder.source_lines', 5),
            );
        });

        $this->app->singleton(CodeContext::class, function (Application $app) {
            return new CodeContext(
                $app->basePath(),
                (bool) $app['config']->get('first-responder.read_code', true),
                (int) $app['config']->get('first-responder.max_symbols', 3),
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

        $this->registerGitHub();

        $this->app->singleton(FirstResponder::class, function (Application $app) {
            $config = (array) $app['config']->get('first-responder', []);
            $config['environment'] = $app->environment();

            return new FirstResponder(
                $app->make(Gatekeeper::class),
                $app->make(SourceExtractor::class),
                $app->make(CodeContext::class),
                $app->make(Redactor::class),
                $app->make(RequestContext::class),
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

            $this->commands([TestCommand::class]);
        }

        $this->registerSentryRoute();
    }

    /**
     * The GitHub issue channel.
     *
     * Registered unconditionally, even when the feature is off. The channel
     * itself checks `github.enabled` and returns; doing the check here instead
     * would mean that naming 'github' in the channel list while it is disabled
     * throws "Driver [github] not supported", which sends people looking for a
     * missing package rather than at their own config.
     *
     * callAfterResolving rather than Notification::extend so the ChannelManager
     * is only built if the application actually sends a notification.
     */
    private function registerGitHub(): void
    {
        $this->app->singleton(GitHubClient::class, function (Application $app) {
            $config = (array) $app['config']->get('first-responder.github', []);

            return new GitHubClient(
                (string) ($config['token'] ?? ''),
                $app->make(LoggerInterface::class),
                (string) ($config['base_url'] ?? 'https://api.github.com'),
                (int) ($config['timeout'] ?? 15),
            );
        });

        $this->app->singleton(RepoRouter::class, function (Application $app) {
            $config = (array) $app['config']->get('first-responder.github', []);

            return new RepoRouter(
                $config['repo'] ?? null,
                array_filter((array) ($config['repo_map'] ?? [])),
            );
        });

        $this->app->singleton(IssueRegistry::class, function (Application $app) {
            $config = (array) $app['config']->get('first-responder.github', []);

            return new IssueRegistry(
                $app->make(CacheFactory::class)->store(),
                $app->make(GitHubClient::class),
                (int) ($config['registry_ttl_days'] ?? 30),
            );
        });

        $this->app->singleton(GitHubChannel::class, function (Application $app) {
            return new GitHubChannel(
                $app->make(GitHubClient::class),
                $app->make(IssueRegistry::class),
                $app->make(RepoRouter::class),
                (array) $app['config']->get('first-responder.github', []),
                $app->make(LoggerInterface::class),
            );
        });

        $this->callAfterResolving(ChannelManager::class, function (ChannelManager $manager, Application $app) {
            $manager->extend('github', fn () => $app->make(GitHubChannel::class));
        });
    }

    /**
     * The Sentry ingest route only exists when it is switched on and has a
     * secret. Registering an unauthenticated public endpoint because somebody
     * half-configured the package would be a bad default.
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
            CodeContext::class,
            RequestContext::class,
            PromptBuilder::class,
            GitHubClient::class,
            GitHubChannel::class,
            IssueRegistry::class,
            RepoRouter::class,
            'first-responder',
        ];
    }
}
