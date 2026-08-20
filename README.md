# Laravel First Responder

**Production broke. This works out why, and says so in chat.**

```
🔴 TypeError: Call to a member function name() on null
at app/Http/Controllers/ProfileController.php:42
on https://example.com/profile/ted-bundy
env: production

Likely cause
Killer::find() returns null when the slug doesn't match and line 42
dereferences it immediately. Use findOrFail(), or guard the null before
reading ->name.
```

The actual line, the code around it, and a read on what went wrong. Sent to Telegram, Slack, Discord, or wherever you already look.

---

## Who this is for

You build the thing and you also fix the thing. One developer, or a handful. No on-call rota, no ops team, no dashboard anyone is watching.

At that size the problem isn't missing alerts, it's ignoring them. An email saying `TypeError in ProfileController` gives you nothing to act on from a phone, so it goes in the "look at it later" pile. This package exists so the message tells you whether it can wait until morning.

Likely to fit if you are:

- A solo founder or small shop running Laravel apps you can't watch continuously
- An agency maintaining client sites, where you need to know which client broke and roughly why before opening the laptop
- Running a side project on one box, where a paid observability tier costs more than the hosting
- On Sentry's free plan and stuck behind the paywall on useful notifications
- Somewhere code can't go to a third party. Point the OpenAI driver at a local Ollama, or turn diagnosis off and still get the line and the source.

Use something else if:

- **You need history, grouping, search or trends.** This is an alerting layer, not an error tracker. No dashboard, no database. Keep Sentry, GlitchTip or Bugsink for the record; this works alongside them.
- **You have a real incident process.** Rotas, escalation, acknowledgement, SLAs. That's PagerDuty or Opsgenie.
- **You have a high-traffic app and a dedicated ops team.** They have dashboards already, and a chat message per new error will annoy them.
- **Your errors are mostly infrastructure.** A stack trace can't tell you the disk filled up.

---

## Why this exists

Both halves of this already existed. Nobody had joined them up.

| | Notifies chat | AI diagnosis | Production | Cost |
|---|---|---|---|---|
| `guanguans/laravel-exception-notify` | ✅ 30+ channels | ❌ | ✅ | Free |
| `spatie/laravel-error-solutions` | ❌ error page only | ✅ | ❌ dev only | Free |
| Sentry Seer | ✅ | ✅ | ✅ | **$40/contributor/mo** |
| **First Responder** | ✅ any channel | ✅ | ✅ | Free + your own token |

If you already use `laravel-exception-notify`, keep it. This uses the same channel packages instead of competing with them.

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

That's it. Out of the box it emails a report with the location and source context. No AI is involved until you turn one on.

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

Any Laravel notification channel works. This package ships none of its own, because that problem is solved and maintaining thirty integrations is a poor use of anyone's time.

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

Sends a real test incident through the whole pipeline and reports each stage: environment, driver, redaction, source extraction, diagnosis, delivery. It prints the message before sending, so a failed send still shows you what it would have said.

```
  Environment ................................................. production
  Enabled ............................................................ yes
  Diagnostician ......................................... OpenAiDiagnostician
  Redaction ........................................................... on
  Incident ......... RuntimeException: First Responder test incident, nothing…
  Location ................ vendor/jeffkolez/…/Console/TestCommand.php:214
  Source context ........................................ read from disk
  Diagnosis ............................................ gpt-4o-mini (734ms)
```

| Option | |
|---|---|
| `--no-ai` | Skip the diagnosis call, so the test costs nothing |
| `--dry` | Show the message without sending it |
| `--queue` | Dispatch through the queue instead of running inline |

### Testing in production

Safe to run. It sends one message, makes one AI call, touches no application data, and does not consume your hourly budget.

It ignores three things that would otherwise stop it: the `environments` gate, the dedupe window and the budget cap. All three exist to suppress reports, so a test command subject to them would do nothing the second time you ran it. It prints a note when it has bypassed one.

Run it in two passes. They test different halves, and the first passing while the second fails is the common case:

```bash
# 1. Config, credentials and delivery. Runs inline, no worker involved.
php artisan first-responder:test

# 2. The queue leg. Hands the real job to the real queue.
php artisan first-responder:test --queue
```

Pass 1 proves your keys and routes are right. Pass 2 proves a worker is consuming the queue, which is how real errors are processed. If pass 1 delivers and pass 2 doesn't, your configuration is fine and your worker is dead, watching a different queue, or running old code.

Neither pass touches the exception handler wiring in `bootstrap/app.php`. To test that:

```bash
php artisan tinker --execute="report(new RuntimeException('Deliberate test'));"
```

That runs the genuine path: Laravel's handler, your reportable callback, the gates, the queue, delivery. It is also the only check subject to the dedupe window, so running it twice should produce one message. That's the throttle working.

---

## Your code does not leak

Diagnosing an error means sending real source and real request data to a third party. That is a new egress path out of your production app, and a reasonable person will want to know what leaves.

Redaction is on by default and runs before anything is serialised, so it also protects the queue payload sitting in Redis or your `jobs` table, not just what the model sees.

Three layers:

**1. Exact environment values.** Any env var whose name looks sensitive (`KEY`, `SECRET`, `TOKEN`, `PASSWORD`, `AUTH`) has its value masked wherever it appears. This is the strongest layer, and no regex can replicate it: we know the literal string, so its shape doesn't matter. Your DB password is caught even though it looks like nothing in particular.

**2. Known token shapes.** `sk-`, `sk-ant-`, `ghp_`, `xox*`, `AKIA`, `AIza`, `sntrys_`, `base64:` app keys, Telegram bot tokens, JWTs, PEM blocks, `Bearer` headers, and passwords embedded in URLs.

**3. Assignment shapes.** `'password' => '…'`, `$token = "…"`, `SECRET=…`.

Where a rule can tell what was masked, the identifying part is kept:

```
'password' => '[redacted]'          not    [redacted]
Authorization: Bearer [redacted]    not    [redacted]
mysql://root:[redacted]@db/app      not    [redacted]
```

The model still learns a password is involved, which is often the useful part, without learning what it is. The prompt also tells it `[redacted]` is deliberate scrubbing, so it doesn't report the mask as the bug.

Want zero egress? Leave `FIRST_RESPONDER_DRIVER=null`. You still get type, message, location and source context in chat.

---

## Your bill does not explode

Two independent limits, because they fail differently.

**Dedupe** covers repeats of one error: one report per distinct error per hour by default.

Identity is the exception class plus the innermost in-app line, not the message. Messages contain variable data, and hashing them shatters one bug into thousands:

```
No query results for model [App\Models\Killer] 4171
No query results for model [App\Models\Killer] 9022
```

That's one missing 404 guard. Fingerprint the message and it becomes two alerts, then two hundred. Since the throttle keys on the fingerprint, getting this wrong disables the throttle rather than just making the output untidy.

**Budget** covers everything else: a hard ceiling of 20 reports an hour by default.

Dedupe alone bounds nothing. A deploy that breaks fifty different things produces fifty distinct fingerprints, all legitimately new, all costing an API call. The cap bounds the total.

Both are claimed before a job is dispatched, so a storm never creates the jobs. Both use atomic cache operations, since a spike is when several workers race on the same fingerprint.

---

## Already using Sentry?

Sentry's chat notifications are a paid feature. Webhooks are not. So you can keep Sentry and add diagnosis on top:

```env
FIRST_RESPONDER_SENTRY_ENABLED=true
FIRST_RESPONDER_SENTRY_SECRET=<Client Secret>
```

In Sentry: **Settings → Developer Settings → Custom Integrations → Internal**, webhook URL `https://your-app.com/first-responder/sentry`, tick **Alert Rule Action** and the **issue** webhook, then add it as an action on an alert rule.

Requests are verified by HMAC over the raw body. The route isn't registered unless a secret is set, so a half-finished setup can't leave an open endpoint.

Sentry is optional. The package works standalone.

---

## File it as a GitHub issue

Chat reaches you in a minute. It is a bad place to keep a list. Add `github` alongside your chat channel and anything worth acting on also becomes an issue — with the failing line, the source around it and the diagnosis already in the body.

```env
FIRST_RESPONDER_GITHUB_ENABLED=true
FIRST_RESPONDER_GITHUB_TOKEN=<fine-grained PAT>
FIRST_RESPONDER_GITHUB_REPO=you/your-app
```

```php
'notifications' => [
    'channels' => ['telegram', 'github'],
    'routes'   => ['telegram' => env('TELEGRAM_ALERT_CHAT_ID')],
],
```

The token needs exactly one permission: **Issues (read and write)** on the target repository.

**Issue bodies contain your source code. File into private repositories only.**

This runs downstream of everything else, which is the point. The ignore list, redaction, the dedupe window and `max_per_hour` all apply already — a deploy that breaks fifty things cannot open fifty issues, for the same reason it cannot send fifty messages.

Two behaviours worth knowing:

- **The same bug does not become eleven issues.** Issues are keyed to the incident fingerprint by a marker in the body, so a recurrence comments on the existing issue instead of filing a new one. If that issue has been closed, the recurrence is treated as a regression and gets its own.
- **A diagnosis the model flagged as uncertain is not filed.** It still reaches chat. A missing diagnosis is different from an unsure one — with no AI configured, everything is filed as normal.

Split frontend and backend into separate repositories? Route by file extension:

```php
'repo_map' => [
    'ts,tsx,js,jsx' => env('FIRST_RESPONDER_GITHUB_REPO_WEB'),
],
```

Anything unmatched falls through to `repo`. Set `FIRST_RESPONDER_GITHUB_DRY_RUN=true` for a day first — it logs what it would file and files nothing.

---

## Configuration worth knowing

| Key | Default | |
|---|---|---|
| `environments` | `['production']` | Empty array means everywhere |
| `ignore` | 404s, validation, auth, CSRF | Subclasses match too |
| `dedupe_minutes` | `60` | `0` disables |
| `max_per_hour` | `20` | `0` disables |
| `source_lines` | `5` | Lines either side of the failure |
| `max_frames` | `3` | |
| `redact` | `true` | |
| `word_limit` | `80` | The message is read on a phone |
| `github.enabled` | `false` | Needs a token as well |
| `github.only_confident` | `true` | Withholds unsure diagnoses; a missing one still files |
| `github.registry_ttl_days` | `30` | How long a fingerprint stays tied to its issue |
| `github.dry_run` | `false` | Logs what it would file, files nothing |

The default ignore list covers things that aren't bugs. A 404 means somebody typed a URL; a 419 means a tab sat open too long. Reporting those teaches you to ignore the channel.

---

## Bring your own model

```php
use JeffKolez\FirstResponder\Contracts\Diagnostician;

$this->app->bind(Diagnostician::class, MyDiagnostician::class);
```

One method: `diagnose(Incident $incident): ?Diagnosis`. Implementations must not throw. Return `null` and the report goes out without a diagnosis, which is a worse message but still an alert.

There is no vendor AI SDK dependency here. At the time of writing the official `laravel/ai` was pre-1.0 and had already swapped its own backend between minors, so this package depends on an interface it owns.

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

Laravel 11 is not supported, and that isn't a choice about effort. `illuminate/mail ^11`, which `illuminate/notifications` depends on and this package needs for the `Notification`, is flagged by a security advisory across every 11.x release, so Composer refuses to install it. Supporting it would mean asking you to set `policy.advisories.block: false` to install an error-monitoring tool. If you're on Laravel 11, upgrade the framework first.

---

## License

MIT. See [LICENSE.md](LICENSE.md).
