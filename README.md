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

Not "an error occurred, here's a link". The actual line, the code around it, and a read on what went wrong — in Telegram, Slack, Discord, or wherever you already look.

---

## Who this is for

**You build the thing and you also fix the thing.** One developer, or a handful. No on-call rota, no ops team, nobody watching a dashboard at 3am — because the dashboard is a browser tab you closed last Tuesday.

At that size the failure mode isn't missing alerts, it's *ignoring* them. An email that says `TypeError in ProfileController` tells you nothing you can act on from your phone, so you file it under "look at it later", and later never comes. The point of this package is that the message contains enough to decide **right now** whether it can wait until morning.

It fits particularly well if you are:

- **A solo founder or small shop** running a handful of Laravel apps you can't watch continuously
- **An agency maintaining client sites** — you need to know which client broke, and roughly why, before you open the laptop
- **Running a side project on one box** where a paid observability tier costs more than the hosting
- **Already on Sentry's free plan** and hitting the paywall on the one feature you wanted: notifications that say something useful
- **Somewhere that can't send code to a third party** — point the OpenAI driver at a local Ollama, or turn diagnosis off entirely and still get the line and the source

**When you should use something else:**

- **You need history, grouping, search, or trends.** This is an alerting layer, not an error tracker. It has no dashboard and no database. Keep Sentry, GlitchTip or Bugsink for the record — this composes with them rather than replacing them.
- **You have a real incident process.** Rotas, escalation, acknowledgement, SLAs — that's PagerDuty or Opsgenie territory and this doesn't pretend otherwise.
- **You have a high-traffic app with a dedicated ops team.** They already have dashboards, and a chat message per new error will annoy them.
- **Your errors are mostly infrastructure**, not code. A diagnosis of a stack trace can't tell you the disk filled up.

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

## Checking it works

```bash
php artisan first-responder:test
```

Sends a real test incident through the whole pipeline and reports each stage — environment, driver, redaction, source extraction, diagnosis, delivery. It prints the exact message it is about to send, so even a failed send tells you what it *would* have said.

```
  Environment ................................................. production
  Enabled ............................................................ yes
  Diagnostician ......................................... OpenAiDiagnostician
  Redaction ........................................................... on
  Incident ......... RuntimeException: First Responder test incident — nothing…
  Location ................ vendor/jeffkolez/…/Console/TestCommand.php:214
  Source context ........................................ read from disk
  Diagnosis ............................................ gpt-4o-mini (734ms)
```

Options:

| | |
|---|---|
| `--no-ai` | Skip the diagnosis call, so the test costs nothing |
| `--dry` | Show the message without sending it |
| `--queue` | Dispatch through the queue instead of running inline |

### Testing in production

Safe to run: it sends one message and makes one AI call. It touches no application data, and it does **not** consume your hourly budget.

It deliberately ignores three things that would otherwise stop it — the `environments` gate, the dedupe window and the budget cap. All three exist to *suppress* reports, so a test command subject to them would refuse to do anything the second time you ran it. It tells you when it has bypassed one.

**Run it in two passes.** They test different halves, and the first one passing while the second fails is the most common way this goes wrong:

```bash
# 1. Config, credentials and delivery — runs inline, no worker involved.
php artisan first-responder:test

# 2. The queue leg — hands the real job to the real queue.
php artisan first-responder:test --queue
```

Pass 1 proves your keys and routes are right. Pass 2 proves a worker is actually consuming the queue, which is how real errors are processed. If pass 1 delivers and pass 2 doesn't, your configuration is fine and your worker is dead, watching a different queue, or running old code after a deploy.

**To test the exception handler itself** — the wiring in `bootstrap/app.php`, which neither pass above touches:

```bash
php artisan tinker --execute="report(new RuntimeException('Deliberate test'));"
```

That goes through the genuine path: Laravel's handler → your reportable callback → the gates → the queue → delivery. It is the only check that proves an actual thrown exception reaches you, and the only one subject to the dedupe window — so if you run it twice you should get exactly one message. That is the throttle working, not a failure.

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
