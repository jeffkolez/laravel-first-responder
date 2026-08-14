# Laravel First Responder

**Production broke. This works out why, and says so in chat.**

```
🔴 TypeError: Call to a member function name() on null
at app/Http/Controllers/ProfileController.php:42
on https://example.com/profile/ted-bundy
env: production

— Likely cause —
Killer::find() returns null when the slug doesn't match and line 42
dereferences it immediately. Use findOrFail(), or guard the null before
reading ->name.
```

Not "an error occurred, here's a link". The actual line, the code around it, and a read on what went wrong — in Telegram, Slack, Discord, or wherever your team already talks.

---

## Why this exists

The two halves of this already existed separately. Nobody had joined them up.

| | Notifies chat | AI diagnosis | Production | Cost |
|---|---|---|---|---|
| `guanguans/laravel-exception-notify` | ✅ 30+ channels | ❌ | ✅ | Free |
| `spatie/laravel-error-solutions` | ❌ error page only | ✅ | ❌ dev only | Free |
| Sentry Seer | ✅ | ✅ | ✅ | **$40/contributor/mo** |
| **First Responder** | ✅ any channel | ✅ | ✅ | Free + your own token |

If you already use `laravel-exception-notify` and like it, keep it — this composes with the same channel packages rather than competing for that job.

---

## Install

```bash
composer require jeffkolez/laravel-first-responder
php artisan vendor:publish --tag=first-responder-config
```

Then report exceptions from `bootstrap/app.php`:

```php
use JeffKolez\FirstResponder\Facades\FirstResponder;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (Throwable $e) {
        FirstResponder::report($e);
    });
})
```

That's it. Out of the box it emails a report with the location and source context, and no AI is involved until you turn one on.

### Send it to Telegram instead

```bash
composer require laravel-notification-channels/telegram
```

```php
'notifications' => [
    'channels' => ['telegram'],
    'routes'   => ['telegram' => env('TELEGRAM_ALERT_CHAT_ID')],
],
```

Any Laravel notification channel works. This package ships none of its own, deliberately — that's a solved problem and maintaining thirty integrations is how a package dies.

### Turn on diagnosis

```env
FIRST_RESPONDER_DRIVER=openai
FIRST_RESPONDER_OPENAI_KEY=sk-...
```

Or `anthropic`. Or point the OpenAI driver at anything speaking the same wire format:

```env
FIRST_RESPONDER_OPENAI_BASE_URL=http://localhost:11434/v1   # Ollama, on your own hardware
```

---

## Your code does not leak

Diagnosing an error means sending real source and real request data to a third party. That is a new egress path out of your production app, and it's the reason a sensible team says no to a package like this.

So redaction is **on by default**, is not a "feature", and runs **before anything is serialised** — which means it also protects the queue payload sitting in Redis or your `jobs` table, not just what the model sees.

Three layers:

**1. Exact environment values.** Any env var whose *name* looks sensitive (`KEY`, `SECRET`, `TOKEN`, `PASSWORD`, `AUTH`, …) has its *value* masked wherever it appears. This is the strongest layer and no regex can replicate it: we know the literal string, so it doesn't matter what shape it has. Your DB password is caught even though it looks like nothing in particular.

**2. Known token shapes.** `sk-`, `sk-ant-`, `ghp_`, `xox*`, `AKIA`, `AIza`, `sntrys_`, `base64:` app keys, Telegram bot tokens, JWTs, PEM blocks, `Bearer` headers, and passwords embedded in URLs.

**3. Assignment shapes.** `'password' => '…'`, `$token = "…"`, `SECRET=…`.

Where a rule can tell *what* was masked, the identifying part is kept:

```
'password' => '[redacted]'          not    [redacted]
Authorization: Bearer [redacted]    not    [redacted]
mysql://root:[redacted]@db/app      not    [redacted]
```

The model still learns a password is involved — often the entire clue — without learning what it is. And the prompt tells it `[redacted]` is deliberate scrubbing, so it doesn't report the mask as the bug.

**Want zero egress?** Leave `FIRST_RESPONDER_DRIVER=null`. You still get type, message, location and source context in chat, which is most of the value.

---

## Your bill does not explode

Two independent limits, because they fail differently.

**Dedupe** answers *"have we already said this?"* — one report per distinct error per hour by default.

Identity is the **exception class plus the innermost in-app line**, deliberately *not* the message. Messages contain variable data, and hashing them shatters one bug into thousands:

```
No query results for model [App\Models\Killer] 4171
No query results for model [App\Models\Killer] 9022
```

That's one missing 404 guard. Fingerprint the message and it's two alerts, then two hundred — and since the throttle keys on the fingerprint, getting this wrong doesn't just look untidy, it defeats the throttle entirely.

**Budget** answers *"have we said too much, full stop?"* — a hard ceiling of 20 reports an hour by default.

Dedupe alone bounds nothing. A deploy that breaks fifty *different* things produces fifty novel fingerprints, every one legitimately new, every one an API call. The cap is what stops an incident becoming an invoice.

Both are claimed **before** a job is dispatched, so a storm never even creates the jobs. Both use atomic cache operations, because a spike is exactly when several workers race on the same fingerprint.

---

## Already using Sentry?

Sentry's chat notifications are a paid feature; **webhooks are not**. So you can keep Sentry and add diagnosis on top:

```env
FIRST_RESPONDER_SENTRY_ENABLED=true
FIRST_RESPONDER_SENTRY_SECRET=<Client Secret>
```

In Sentry: **Settings → Developer Settings → Custom Integrations → Internal**, webhook URL `https://your-app.com/first-responder/sentry`, tick **Alert Rule Action** and the **issue** webhook, then add it as an action on an alert rule.

Requests are verified by HMAC over the raw body. The route isn't registered at all unless a secret is set, so a half-finished setup can't leave an open endpoint lying around.

Sentry is entirely optional — the package works standalone.

---

## Configuration worth knowing

| Key | Default | |
|---|---|---|
| `environments` | `['production']` | Empty array means everywhere |
| `ignore` | 404s, validation, auth, CSRF | Subclasses match too |
| `dedupe_minutes` | `60` | `0` disables |
| `max_per_hour` | `20` | `0` disables — think about the bill |
| `source_lines` | `5` | Lines either side of the failure |
| `max_frames` | `3` | Past three nobody is reading |
| `redact` | `true` | Leave it on |
| `word_limit` | `80` | This is read on a phone |

The default ignore list covers the things that **aren't bugs** — a 404 means somebody typed a URL, a 419 means a tab sat open too long. Reporting those trains you to ignore the channel, which is the only real failure mode for a tool like this.

---

## Bring your own model

```php
use JeffKolez\FirstResponder\Contracts\Diagnostician;

$this->app->bind(Diagnostician::class, MyDiagnostician::class);
```

One method: `diagnose(Incident $incident): ?Diagnosis`. Implementations **must not throw** — return `null` and the report goes out without a diagnosis. Losing the explanation degrades the message; losing the alert loses the outage.

There's a deliberate non-dependency here: no vendor AI SDK. At the time of writing the official `laravel/ai` was pre-1.0 and had already swapped its own backend between minors. This package depends on an interface it owns instead.

---

## Testing

```bash
composer test
```

Bind the null driver in your own tests and nothing touches the network:

```php
$this->app->bind(Diagnostician::class, NullDiagnostician::class);
```

**Requires PHP 8.2+ and Laravel 12 or 13.**

Laravel 11 is not supported, and that is not a choice about effort. `illuminate/mail ^11` — which `illuminate/notifications` depends on, and this package needs for the `Notification` — is flagged by a security advisory across every 11.x release, so Composer refuses to install it. Supporting a version that cannot be resolved without `policy.advisories.block: false` would mean asking you to switch off a security check to install an error-monitoring tool. If you are on Laravel 11, upgrade the framework first.

---

## License

MIT. See [LICENSE.md](LICENSE.md).
