<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. Off by default in the published config would be a footgun
    | (people install, see nothing, assume it's broken), so it is on — but the
    | `environments` key below keeps it quiet outside production.
    |
    */

    'enabled' => env('FIRST_RESPONDER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Environments
    |--------------------------------------------------------------------------
    |
    | Only report in these. An empty array means every environment, which is
    | useful while you are setting it up and miserable afterwards.
    |
    */

    'environments' => ['production'],

    /*
    |--------------------------------------------------------------------------
    | Ignored exceptions
    |--------------------------------------------------------------------------
    |
    | Subclasses are matched too, so listing a base class covers its children.
    |
    | These defaults are the ones that are not bugs: a 404 means somebody typed
    | a URL, a validation error means a form was filled in wrong, a 419 means a
    | tab sat open too long. Reporting them trains you to ignore the channel,
    | which is the only real failure mode for a tool like this.
    |
    */

    'ignore' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | `dedupe_minutes` — how long one distinct error stays quiet after being
    | reported. Identity is the exception class plus the innermost in-app line,
    | NOT the message, so "record 4171 not found" and "record 9022 not found"
    | count as the same bug. Set 0 to disable.
    |
    | `max_per_hour` — a hard ceiling on reports, and therefore on spend.
    | Dedupe alone bounds nothing: a deploy that breaks fifty different things
    | produces fifty novel fingerprints and fifty AI calls. Set 0 to disable,
    | but think about the bill first.
    |
    */

    'dedupe_minutes' => env('FIRST_RESPONDER_DEDUPE_MINUTES', 60),

    'max_per_hour' => env('FIRST_RESPONDER_MAX_PER_HOUR', 20),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Diagnosis and delivery run off the request. Leave null for the defaults.
    |
    */

    'queue' => env('FIRST_RESPONDER_QUEUE'),

    'queue_connection' => env('FIRST_RESPONDER_QUEUE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Runs before ANYTHING is serialised — so it protects the queue payload as
    | well as whatever the diagnostician sees. Turning it off is not advisable
    | and the option exists mainly so the decision is a visible one.
    |
    | Values of environment variables whose NAME looks sensitive (KEY, SECRET,
    | TOKEN, PASSWORD, …) are masked wherever they appear, which catches
    | credentials that no regex would recognise. `literals` adds your own.
    |
    */

    'redact' => env('FIRST_RESPONDER_REDACT', true),

    'redact_emails' => env('FIRST_RESPONDER_REDACT_EMAILS', true),

    'redact_patterns' => [
        // '/\bacct_[A-Za-z0-9]{16,}/',
    ],

    'redact_literals' => [
        // 'a-value-you-know-is-secret',
    ],

    /*
    |--------------------------------------------------------------------------
    | Source context
    |--------------------------------------------------------------------------
    |
    | Lines either side of the failing line. This is what makes a diagnosis
    | worth reading; it is also what gets sent onward, hence the redaction above.
    |
    */

    'source_lines' => 5,

    'max_frames' => 3,

    /*
    |--------------------------------------------------------------------------
    | Diagnostician
    |--------------------------------------------------------------------------
    |
    | 'null' — no AI. Reports still carry type, message, location and source,
    | which is most of the value. Use this if code must not leave your network.
    |
    | 'openai' — also speaks to anything OpenAI-compatible. Point base_url at
    | Azure, OpenRouter, Groq, or a local Ollama to keep it on-premises.
    |
    | 'anthropic' — the Messages API.
    |
    | Or bind your own implementation of the Diagnostician contract.
    |
    */

    'diagnostician' => env('FIRST_RESPONDER_DRIVER', 'null'),

    'word_limit' => 80,

    'diagnosticians' => [

        'openai' => [
            'key' => env('FIRST_RESPONDER_OPENAI_KEY', env('OPENAI_API_KEY')),
            'model' => env('FIRST_RESPONDER_OPENAI_MODEL', 'gpt-4o-mini'),
            'base_url' => env('FIRST_RESPONDER_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'timeout' => 20,
        ],

        'anthropic' => [
            'key' => env('FIRST_RESPONDER_ANTHROPIC_KEY', env('ANTHROPIC_API_KEY')),
            'model' => env('FIRST_RESPONDER_ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
            'base_url' => env('FIRST_RESPONDER_ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'timeout' => 20,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Standard Laravel notification channels. This package ships no channel
    | drivers of its own on purpose — install any community channel package and
    | name it here, and it works.
    |
    | e.g. 'channels' => ['telegram'],
    |      'routes'   => ['telegram' => env('TELEGRAM_ALERT_CHAT_ID')],
    |
    */

    'notifications' => [

        'channels' => ['mail'],

        'routes' => [
            'mail' => env('FIRST_RESPONDER_MAIL_TO'),
            // 'telegram' => env('TELEGRAM_ALERT_CHAT_ID'),
            // 'slack' => env('SLACK_WEBHOOK_URL'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Sentry ingest (optional)
    |--------------------------------------------------------------------------
    |
    | If you already run Sentry, point a Sentry Internal Integration at
    | POST /first-responder/sentry and its issue alerts flow through the same
    | pipeline. Sentry is NOT required to use this package.
    |
    | `secret` is the integration's Client Secret and is the only thing
    | authenticating that route. Blank disables the route entirely.
    |
    */

    'sentry' => [

        'enabled' => env('FIRST_RESPONDER_SENTRY_ENABLED', false),

        'secret' => env('FIRST_RESPONDER_SENTRY_SECRET'),

        'path' => 'first-responder/sentry',

        'middleware' => ['api'],

    ],

];
