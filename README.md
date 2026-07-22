# MeadBotAPI

REST API for MeadBot calculator functionality.

This is a PHP port of the pure calculation functions exported by
[`CalculatorAPI.js`](https://github.com/jceddy/MeadBot/blob/main/src/calculator/CalculatorAPI.js)
in the [MeadBot](https://github.com/jceddy/MeadBot) repository — the mead-brewing math (ABV,
calories, Delle number, unit conversions, dry-FG estimation, sugar-source/unit lookups) with no
Discord.js dependency, served over HTTP as JSON.

## Requirements

- PHP 8.1+
- [Composer](https://getcomposer.org/)

## Setup

```
composer install
```

## Running locally

```
composer run serve
```

This starts PHP's built-in server on `http://localhost:8000`, serving `public/` as the document
root — the API under `/api/v1`, interactive docs at `/docs` (see [API docs](#api-docs) below),
and a browser-based UI at `/app` (see [Web app](#web-app) below). For production, point your web
server's document root at `public/` (an `.htaccess` is included for Apache + `mod_rewrite`; for
nginx, route unmatched requests to `index.php`).

## Deployment

`.github/workflows/deploy.yml` deploys to production automatically on every push to `main`
(i.e. whenever a PR is merged): it runs the test suite, rebuilds `vendor/` without dev
dependencies, writes a `.env` file at the repo root (see [Chat agent](#chat-agent) below), and
uploads the repo (minus `.github/`, `tests/`, and VCS files) over FTP to the server root, using
the repository secrets `FTP_HOST`, `FTP_USERNAME`, and `FTP_PASSWORD`. This assumes the web
server's document root points at the `public/` subfolder of that upload directory, since
`public/index.php` loads `vendor/`, `src/`, and `.env` via paths relative to itself.

## API docs

`public/docs/openapi.yaml` is an OpenAPI 3.0 spec covering every endpoint below, and
`public/docs/index.html` renders it as interactive Swagger UI at `/docs` — since it lives under
`public/`, it's served automatically by `composer run serve` locally and is reachable at
`/docs` on the deployed API too. The `swagger-ui-dist` assets are vendored under
`public/docs/vendor/swagger-ui/` (not loaded from a CDN), so the docs page works offline and
isn't subject to a third party's availability.

## Tests

```
composer test
```

Test cases assert parity with reference values captured by running the original
`CalculatorAPI.js` under Node.js.

## API

All endpoints live under `/api/v1` and return JSON. Parameters can be supplied as a JSON request
body (`Content-Type: application/json`), as query-string parameters, or (for path parameters) in
the URL itself — path parameters take precedence, then JSON body, then query string.

Responses that represent a calculation result always include an `error` boolean. When `error` is
`true`, the response also includes `errorMessage`, and usually `errorArgument`,
`errorArgumentPosition`, `errorType` (numeric code), and `errorTypeLabel` (string). The HTTP
status code is `400` whenever `error` is `true`, `404` for an unknown route, `405` for a known
route called with the wrong HTTP method, and `200` otherwise.

| Method | Path | Body / query params | Mirrors |
| --- | --- | --- | --- |
| GET | `/api/v1/health` | — | — |
| POST | `/api/v1/calories` | `percentAlcohol`, `fg`, `bottleVolume`, `servingVolume` | `CalculateCalories` |
| POST | `/api/v1/abv` | `og`, `fg` (optional — estimated "dry" FG is used if omitted) | `CalculateABV` |
| POST | `/api/v1/gravity-drop-to-abv` | `sgDelta` | `ConvertGravityDropToABV` |
| POST | `/api/v1/dry-fg` | `og` | `EstimateDryFG` |
| GET | `/api/v1/volume-units` | — | `!list-volume-units` |
| GET | `/api/v1/volume-units/{name}` | — | `GetVolumeUnit` |
| POST | `/api/v1/volume/convert` | `amount`, `fromUnit`, `toUnit` | `ConvertVolume` |
| GET | `/api/v1/honey-units/{name}` | — | `GetHoneyUnit` |
| POST | `/api/v1/honey/convert` | `amount`, `fromUnit`, `toUnit` | `ConvertHoneyUnits` |
| POST | `/api/v1/temperature/convert` | `fromTemperature`, `fromUnit` (`c`/`celcius`/`f`/`fahrenheit`) | `ConvertTemperature` |
| POST | `/api/v1/sg-to-brix` | `sg` | `ConvertSGToBrix` |
| POST | `/api/v1/delle` | `abv`, `sg` | `ComputeDelle` |
| POST | `/api/v1/potential-alcohol` | `gravityUnits`, `abvUnits`, and at least one of `og`/`fg`/`abv` (see [docs](#api-docs) for the solve priority) | `!potential-alcohol`\* |
| POST | `/api/v1/calculate-blend` | `fieldToCalculate` and 4-5 of `value1`/`value2`/`blendedValue`/`volume1`/`volume2`/`totalVolume` (see [docs](#api-docs)) | `!calculate-blend` |
| POST | `/api/v1/calculate-nutrients` | All optional — `units`, `volume`, `yan`, and various nutrient-limit/ratio overrides (see [docs](#api-docs)) | `!calculate-nutrients` |
| POST | `/api/v1/build-batch` | All optional — `units`, `volume`, `yeastAbv`, `nutrientRegimen`, and many more (see [docs](#api-docs)) | `!build-batch` |
| POST | `/api/v1/calculate-mead` | All optional — `units`, `targetGravity`/`targetVolume`/`targetAbv` (any two solve the third), `additionalSugars`, and many more (see [docs](#api-docs)) | `!calculate-mead` |
| GET | `/api/v1/yeast-requirements` | — | `!list-yeast-requirements` |
| GET | `/api/v1/sugar-sources/{name}` | — | `GetSugarSourceIdentifier` |
| POST | `/api/v1/dates/days-between` | `date1`, `date2` (parseable date/time strings) | `GetDaysBetween` |
| POST | `/api/v1/dates/months-between` | `date1`, `date2`, `roundUpFractionalMonths` (optional bool) | `GetMonthsBetween` |
| GET | `/api/v1/random` | `max` | `RandomInteger` |
| POST | `/api/v1/hours-string` | `timing`, `break3` (required only when `timing` is `"break"`) | `MakeHoursString` |
| POST | `/api/v1/chat` | `messages` (OpenAI-style conversation). Requires header `X-Api-Key`. | — (see [Chat agent](#chat-agent)) |
| POST | `/api/v1/chat/web` | `messages` (same shape as `/chat`). Requires a logged-in session (see [Web app](#web-app)) instead of `X-Api-Key`. | — (see [Web app](#web-app)) |
| GET | `/api/v1/auth/discord/login` | — . Redirects to Discord. | — (see [Web app](#web-app)) |
| GET | `/api/v1/auth/discord/callback` | `code`, `state` (from Discord's redirect). Redirects to `/app/`. | — (see [Web app](#web-app)) |
| POST | `/api/v1/auth/logout` | — | — (see [Web app](#web-app)) |
| GET | `/api/v1/auth/me` | — | — (see [Web app](#web-app)) |
| POST | `/api/v1/chat/feedback` | `discordUserId`, `discordMessageId`, `messages`, `discordChannelId`/`discordGuildId` (optional). Requires header `X-Api-Key`. | — (see [Chat agent](#chat-agent)) |
| GET | `/api/v1/balance` | — . Requires header `X-Api-Key`. | — (see [Balance ledger](#balance-ledger)) |
| POST | `/api/v1/balance/deposits` | `amountUsd`, `note` (optional). Requires header `X-Api-Key`. | — (see [Balance ledger](#balance-ledger)) |
| GET | `/api/v1/balance/usage-by-user` | — . Requires header `X-Api-Key`. | — (see [Per-user usage](#per-user-usage)) |

\* `/potential-alcohol` mirrors the *intent* of MeadBot's `!potential-alcohol` command. That
command originally had two bugs — a specified value that happened to equal its default being
silently ignored, and BRIX/BAUME inputs not being converted to SG before use in two of its three
solve branches — both since fixed in the MeadBot repo too. One difference remains: MeadBot's
"solve fg" branch (`og`+`abv` given) still uses a different ABV↔SG approximation than its
"solve og" branch (`fg`+`abv` given), while this endpoint uses the same formula (an iterative
search against the real cubic ABV formula) for both, since it has no existing consumers to
preserve that inconsistency for.

### Examples

```
curl -s http://localhost:8000/api/v1/abv \
  -H 'Content-Type: application/json' \
  -d '{"og": 1.100, "fg": 1.000}'
# {"error":false,"og":1.1,"fg":1,"abv":13.187}

curl -s "http://localhost:8000/api/v1/volume-units/gallon"
# {"error":false,"unitId":1}

curl -s http://localhost:8000/api/v1/volume/convert \
  -H 'Content-Type: application/json' \
  -d '{"amount": 1, "fromUnit": "gallon", "toUnit": "liters"}'
# {"error":false,"fromAmount":1,"fromUnit":{"name":"Gallon(s) US","conversion":3.7854117891},"toAmount":3.7854117891,"toUnit":{"name":"Liter(s)","conversion":1}}
```

## Chat agent

`POST /api/v1/chat` runs a chat-completions tool-calling loop against
[Fireworks AI](https://fireworks.ai) (an OpenAI-compatible API) with every calculation/lookup
endpoint above — except `/health` and `/random` — exposed to the model as a tool. When the model
calls one, it's executed locally (the same `Http\Operations` method the matching REST route
calls, not an HTTP round-trip to this same API) and the result is fed back to the model, repeating
until it replies with plain text.

Two more tools, both in `Chat\MeadToolsWikiClient`, ground mead-making judgment calls (recipe
design, technique, troubleshooting — as opposed to pure calculations) in
[wiki.meadtools.com](https://wiki.meadtools.com/en/home) instead of the model's training data,
which was observed giving bad advice on its own:

- `list_meadtools_wiki_pages` returns a hand-maintained index of pages on that wiki, from
  `src/Chat/data/meadtools_wiki_index.json` — title, url, level (0 = home, 1 = linked from home,
  2 = linked from a level-1 page), category tags, a one-sentence summary, keywords, and
  related_pages (other page urls on the same topic, so the model doesn't have to discover them by
  reading the page first) — so the model can pick a relevant page directly instead of crawling
  link-by-link from the home page (which reliably burned through the tool-call iteration cap
  without finding the right page). The index is static and updated by hand on no fixed schedule
  — regenerate it in the same shape and replace that file whenever the wiki's content changes
  enough to matter; nothing here does that automatically.
- `fetch_meadtools_wiki_page` fetches a given page from that same host — restricted to
  `wiki.meadtools.com` specifically, both on the requested URL and wherever it ends up after any
  redirect, since an LLM-directed "fetch any URL" tool would otherwise be an SSRF risk — and
  returns its readable text plus same-host links, for drilling into pages the index doesn't cover.

This endpoint is stateless: it keeps no server-side session, so pass the `messages` array from
the response back in as the next request's `messages` (with a new `{role: "user", ...}` turn
appended) to continue a conversation.

```
curl -s -X POST http://localhost:8000/api/v1/chat \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: <CHAT_API_KEY>' \
  -d '{"messages": [{"role": "user", "content": "I have 5 gallons at 1.100 SG. What'\''s my potential ABV?"}]}'
# {"error":false,"reply":"...","messages":[...],"usage":{"prompt_tokens":2143,"cached_prompt_tokens":1980,"completion_tokens":42,"total_tokens":2185},"costUsd":0.0000572}
```

The response's `usage` is the *sum* across every Fireworks call the request made — one `/chat`
call can trigger several (one per tool-calling round) — split into `prompt_tokens` (total input),
`cached_prompt_tokens` (the subset that hit Fireworks' prompt cache, billed at a lower rate), and
`completion_tokens`. `costUsd` is computed from that using the requested model's pricing (see
[Setup](#setup) below); it's an estimate for cost tracking, not a billing-authoritative figure
(Fireworks' own dashboard is). Both fields are included even on a `400` error response whenever at
least one underlying call completed before the failure (e.g. a later tool-call round failed, or
the agent hit its iteration cap) — those calls were still billed by Fireworks regardless of
whether the request as a whole succeeded.

### Negative feedback

`POST /api/v1/chat/feedback` records a Discord 👎 reaction on one of MeadBot's `!chat`/`!ask`
replies — one row per reaction, storing the reconstructed conversation (ending with the disliked
reply) as JSON, plus the reacting user's Discord ID and the message/channel/guild IDs needed to
find it again. MeadBot's `messageReactionAdd` handler calls this once it's confirmed the
reacted-to message really was a chat reply; it's not a chat-agent tool and the model never calls
it itself.

```
curl -s -X POST http://localhost:8000/api/v1/chat/feedback \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: <CHAT_API_KEY>' \
  -d '{"discordUserId": "123", "discordMessageId": "456", "discordGuildId": "789", "messages": [{"role": "user", "content": "..."}, {"role": "assistant", "content": "..."}]}'
# {"error":false}
```

This shares the same `MYSQL_DB_*` configuration as the balance ledger below (see its
[Setup](#setup-1) for the required secrets and how to apply migrations) — no additional secrets
are needed. If the database isn't configured/reachable, this responds with
`{"error":true,"errorMessage":"The feedback database is not configured on this server."}`.

### Setup

Because this endpoint makes paid, per-request Fireworks calls (with potentially several
tool-calling round trips) and the rest of the API is otherwise open with no auth, it requires two
secrets that aren't needed for anything else here:

- `FIREWORKS_API_KEY` — your [Fireworks account](https://fireworks.ai) API key.
- `CHAT_API_KEY` — a shared secret of your choosing; callers must send it as the `X-Api-Key`
  header. Generate one yourself, e.g. `openssl rand -hex 32`.

Add both as **GitHub Actions repository secrets** on this repo (same place as `FTP_HOST` etc.) —
`deploy.yml` writes them into a `.env` file at the repo root on every deploy (outside `public/`,
so it's never web-accessible), which `src/Http/Env.php` loads at request time via `getenv()`. If
either is missing/empty, `/api/v1/chat` responds with `{"error":true,"errorMessage":"Chat is not
configured on this server."}` instead of failing open — every other endpoint is unaffected.

For local development, create a `.env` file at the repo root (gitignored, not deployed by CI)
with the same two lines:

```
FIREWORKS_API_KEY=...
CHAT_API_KEY=...
```

`POST /chat` accepts an optional `model` field selecting which Fireworks-hosted model to run that
turn against, from a small hardcoded catalog in `src/Chat/ModelCatalog.php`:

| `model` | Fireworks model | Price per 1M tokens (input / cached input / output) |
| --- | --- | --- |
| `ds` (default) | `accounts/fireworks/models/deepseek-v4-flash` | $0.14 / $0.028 / $0.28 |
| `gpt` | `accounts/fireworks/models/gpt-oss-120b` (OpenAI's open-weight reasoning/tool-calling model) | $0.15 / $0.014 / $0.60 |

Omitting `model` (or MeadBot's `!chat` command omitting its `--model`/`-m` flag) uses `ds`. An
unrecognized `model` value gets a `400` error listing the valid keys. `costUsd` in the response is
computed from the requested model's rates above; there's no env-var override for these — add a
new entry to `ModelCatalog` (and update this table) if Fireworks' pricing changes or another model
is worth adding.

## Web app

`/app` (`public/app/`) is a small browser UI, served as static files alongside the API — plain
HTML/CSS/vanilla JS, no build step, no framework, no npm dependency (the only vendored
third-party code in this repo remains Swagger UI under `public/docs/`). It has two tabs:

- **Calculators** — a form for every calculator/lookup endpoint in the [API](#api) table above
  (everything except `/health`, `/chat`, `/chat/web`, `/chat/feedback`, `/balance*`, and
  `/auth/*`), generated from a declarative config in `app.js` rather than hand-written per
  endpoint. These call the same public, unauthenticated REST routes directly — no login needed.
- **Chat** — a browser client for the chat agent (see [Chat agent](#chat-agent) above), gated
  behind Discord login (see below).

### Why chat needs a login

`/api/v1/chat` is gated by `CHAT_API_KEY` because every call triggers paid Fireworks usage.
That key can't be shipped to a public browser client — anyone could view-source it and call
`/api/v1/chat` directly, unlimited, bypassing whatever the UI does. Instead, `POST
/api/v1/chat/web` requires a logged-in session (no `X-Api-Key`) and proxies into the same
[`runChat()`](public/index.php) logic `/api/v1/chat` uses. The login itself is "Login with
Discord" (OAuth2, `identify` scope only — no email or other data requested): the logged-in
user's Discord snowflake ID is used as the ledger's `userId`, the same identifier space
MeadBot's `X-User-Id` header already populates for bot usage, so one person's Discord-bot and
web-app chat usage aggregate together automatically in `GET /balance/usage-by-user` (see
[Per-user usage](#per-user-usage)) — no separate account system, and no additional rate-limiting,
consistent with the ledger's tracking-only philosophy.

The flow: `GET /api/v1/auth/discord/login` redirects to Discord (storing a random `state` value
in the session first, to guard against CSRF); Discord redirects back to `GET
/api/v1/auth/discord/callback` with a `code`, which is validated against that `state` and
exchanged for the user's Discord id, stored in the PHP session; the browser is then redirected
back to `/app/`. `GET /api/v1/auth/me` reports the current login state (used by `app.js` to
toggle the login/logout buttons and the chat form), and `POST /api/v1/auth/logout` clears the
session. The session cookie is `httponly`/`SameSite=Lax` (and `Secure` whenever the request
arrived over HTTPS, including behind a reverse proxy that forwards `X-Forwarded-Proto`), so it
isn't readable or forgeable from JS or a cross-site request.

### Setup

Requires a Discord application's OAuth2 credentials — reusing MeadBot's existing Discord
application (from its [Developer Portal](https://discord.com/developers/applications) entry) is
the simplest option, since it needs no new bot/permissions, just an OAuth2 redirect registered
for this API's callback URL:

- `DISCORD_CLIENT_ID` — the application's client ID.
- `DISCORD_CLIENT_SECRET` — the application's client secret.
- `DISCORD_REDIRECT_URI` — this API's callback URL, e.g.
  `https://api.example.com/api/v1/auth/discord/callback` — must also be added under
  **OAuth2 → Redirects** on the Discord application, exactly matching (Discord rejects a
  mismatch).

Add all three as **GitHub Actions repository secrets** (same place as `FTP_HOST` etc.) —
`deploy.yml` writes them into the same `.env` file as the chat secrets. If any is missing/empty,
`/api/v1/auth/discord/login` and `/api/v1/auth/discord/callback` respond with
`{"error":true,"errorMessage":"Discord login is not configured on this server."}`, and the web
app's Chat tab shows a "not configured" state instead of a login button — the Calculators tab and
every other endpoint are unaffected.

For local development, add the same three lines to your `.env` file (gitignored, not deployed by
CI), using a redirect URI pointing at your local server, e.g.
`DISCORD_REDIRECT_URI=http://localhost:8000/api/v1/auth/discord/callback` (also registered under
that same application's OAuth2 redirects).

## Balance ledger

A small MySQL-backed ledger tracks the prepaid Fireworks balance: every `/api/v1/chat` request
logs its `usage`/`costUsd` (see above) as a deduction, and `POST /api/v1/balance/deposits` records
a top-up. `GET /api/v1/balance` returns the running total — always recomputed as
`SUM(deposits) - SUM(usage)` rather than stored as a column, so it can't drift out of sync.

This is purely informational/tracking — **`/api/v1/chat` never checks the balance or refuses
requests based on it**, so a bug in the ledger can't accidentally lock out a working endpoint.
Watch the numbers yourself (or build alerting on top of the database) rather than relying on this
to enforce a spending cap.

```
curl -s -X POST http://localhost:8000/api/v1/balance/deposits \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: <CHAT_API_KEY>' \
  -d '{"amountUsd": 50, "note": "Fireworks top-up 2026-07-19"}'
# {"error":false,"deposit":{"amountUsd":50,"note":"..."},"balance":{"totalDepositsUsd":50,"totalUsageUsd":0,"balanceUsd":50}}

curl -s -H 'X-Api-Key: <CHAT_API_KEY>' http://localhost:8000/api/v1/balance
# {"error":false,"balance":{"totalDepositsUsd":50,"totalUsageUsd":1.23,"balanceUsd":48.77}}
```

`amountUsd` may be negative for a manual correction to the ledger (e.g. reconciling against
Fireworks' own billing dashboard) — there's no separate "adjustments" concept, just deposits that
happen to be negative.

### Per-user usage

Pass an optional `X-User-Id` header on `POST /api/v1/chat` to tag that request's usage row with a
caller-supplied identifier (opaque to this app — not validated, not sent to Fireworks). Omit it
and the row is grouped under a `null` user. `GET /api/v1/balance/usage-by-user` returns per-user
totals, ordered by total cost descending — this is what answers "who's using chat the most," and
is the data future per-user rate-limiting would build on (not implemented yet — this endpoint is
tracking/reporting only, same as the rest of the ledger).

```
curl -s -X POST http://localhost:8000/api/v1/chat \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: <CHAT_API_KEY>' \
  -H 'X-User-Id: alice' \
  -d '{"messages": [{"role": "user", "content": "..."}]}'

curl -s -H 'X-Api-Key: <CHAT_API_KEY>' http://localhost:8000/api/v1/balance/usage-by-user
# {"error":false,"usageByUser":[{"userId":"alice","requestCount":12,"totalUsageUsd":0.34,"totalTokens":48213,"lastUsedAt":"2026-07-19 23:58:56"}, ...]}
```

### Setup

Requires four secrets, plus one optional one, none needed by anything else here:

- `MYSQL_DB_HOST`, `MYSQL_DB_DATABASE`, `MYSQL_DB_USERNAME`, `MYSQL_DB_PASSWORD` — credentials for
  a MySQL/MariaDB database this app can reach.
- `MYSQL_DB_PORT` (optional) — only needed if the database isn't listening on MySQL's default
  port (3306), e.g. a hosting provider that runs multiple instances on one box.

Add these as **GitHub Actions repository secrets** (same place as `FTP_HOST` etc.) — `deploy.yml`
writes them into the same `.env` file as the chat secrets. If any of the four required ones is
missing, `/chat` still works exactly as before (usage-logging silently no-ops rather than failing
the request), but `/balance`, `/balance/deposits`, and `/balance/usage-by-user` respond with
`{"error":true,"errorMessage":"The balance database is not configured on this server."}` — the
same response you'll see if the port is wrong and the connection just fails, so a bad
`MYSQL_DB_PORT` looks identical to a missing secret from the API's point of view.

**Schema changes are never applied automatically** — neither this session nor GitHub Actions has
network access to the database. Numbered SQL files under `migrations/` describe each schema
change; apply them yourself, in order, against the database identified by the secrets above (add
`--port=<MYSQL_DB_PORT>` if you set one):

```
mysql --host=<MYSQL_DB_HOST> --user=<MYSQL_DB_USERNAME> -p <MYSQL_DB_DATABASE> < migrations/0001_create_chat_usage.sql
mysql --host=<MYSQL_DB_HOST> --user=<MYSQL_DB_USERNAME> -p <MYSQL_DB_DATABASE> < migrations/0002_create_balance_deposits.sql
mysql --host=<MYSQL_DB_HOST> --user=<MYSQL_DB_USERNAME> -p <MYSQL_DB_DATABASE> < migrations/0003_add_user_id_to_chat_usage.sql
mysql --host=<MYSQL_DB_HOST> --user=<MYSQL_DB_USERNAME> -p <MYSQL_DB_DATABASE> < migrations/0004_create_chat_feedback.sql
```

For local development, add the same lines to your `.env` file (gitignored, not deployed by CI)
pointing at a local or dev database you've applied the same migrations to.

## Project structure

- `public/index.php` - front controller; defines all routes (each a thin call into
  `Http\Operations`) plus the `/api/v1/chat` route.
- `src/Http/Operations.php` - one method per REST endpoint: parses/defaults that endpoint's
  params and calls the matching calculator method. Shared by `public/index.php` (as REST route
  handlers) and `Chat\Tools` (as tool-calling handlers), so both stay in sync by construction.
- `src/Http/Router.php` - a minimal method+path router used by `public/index.php`.
- `src/Http/Env.php` - minimal `.env` file loader (no external dependency); see
  [Chat agent](#chat-agent).
- `src/Calculator/CalculatorApi.php` - the ported calculator methods.
- `src/Calculator/GravityCalculator.php` - gravity/ABV unit conversions and the
  `potentialAlcohol` solver, ported from `GravityCalculator.js`.
- `src/Calculator/BlendCalculator.php` - the two-liquid blend solver, ported from
  `BlendCalculator.js`.
- `src/Calculator/NutrientCalculator.php` - the SNA nutrient-schedule solver, ported from
  `NutrientCalculator.js`.
- `src/Calculator/BatchCalculator.php` - full-recipe (honey/yeast/nutrients) orchestration,
  ported from `BatchCalculator.js`.
- `src/Calculator/Constants.php` - unit tables, error-type codes, and sugar-source data, ported
  from `CalculatorAPI.Constants.js`.
- `src/Chat/Tools.php` - OpenAI-style tool schemas for the chat agent, dispatching to
  `Http\Operations`.
- `src/Chat/FireworksClient.php` - minimal client for Fireworks' OpenAI-compatible
  chat-completions endpoint.
- `src/Chat/ChatAgent.php` - the tool-calling loop used by `/api/v1/chat`.
- `src/Chat/CostCalculator.php` - turns a token-usage total into an estimated USD cost.
- `src/Chat/ModelCatalog.php` - the `gpt`/`ds` model keys `/api/v1/chat`'s `model` field accepts,
  each mapped to a Fireworks model id and per-1M-token pricing.
- `src/Ledger/Ledger.php` - the balance ledger (usage logging, deposits, running balance) used by
  `/api/v1/chat` and `/api/v1/balance*`; see [Balance ledger](#balance-ledger).
- `src/Auth/DiscordOAuth.php` - minimal Discord OAuth2 client used by `/api/v1/auth/discord/*`;
  see [Web app](#web-app).
- `migrations/` - numbered SQL files describing the ledger's schema; apply manually (see
  [Balance ledger](#balance-ledger)) — never applied automatically.
- `tests/` - PHPUnit tests, run with `composer test`.
- `public/docs/` - OpenAPI spec and Swagger UI, served at `/docs`.
- `public/app/` - the browser UI (calculators + Discord-gated chat); see [Web app](#web-app).
