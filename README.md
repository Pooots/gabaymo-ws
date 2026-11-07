# gabaymo-ws

Laravel API gateway for **GabayMo**: school-head members, partner lawyers and staff (admin)
sign in here. It owns accounts, roles and permissions, PayMongo payments and paid periods,
token metering, partner tickets, reminders and notifications (Laravel Reverb), and forwards
AI requests to **gabaymo-ai** with signed service assertions.

- API contract for this round: [`../docs/IMPROVEMENTS-CONTRACT.md`](../docs/IMPROVEMENTS-CONTRACT.md)
  (section B is this service, section A the gabaymo-ai endpoints it forwards to).
- Service-to-service signing: `app/Services/GabayMoAi/ServiceSignature.php` (kept in sync with gabaymo-ai).

## Requirements

PHP 8.3+, Composer, MySQL 8 (production / local dev) and SQLite (tests only).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# Local development over http: APP_DEBUG=true, SESSION_SECURE_COOKIE=false
php artisan migrate:fresh --seed    # schema + roles, plans, instructions, one admin, one partner
php artisan serve                   # API on http://127.0.0.1:8000
php artisan queue:work              # emails and other queued jobs
php artisan reverb:start            # realtime notifications
php artisan schedule:work           # scheduled commands (production: cron `schedule:run` every minute)
```

### Seeding

`DatabaseSeeder` seeds reference data (roles and permissions, subscription plans, service
instructions) plus exactly **one admin** (system `admin` role, every module) and **one partner**.
It never creates member accounts; members register themselves. Every seeder is safe to re-run.

| Key | Default | |
|---|---|---|
| `SEED_ADMIN_EMAIL`, `SEED_ADMIN_NAME` | `admin@gabaymo.local`, `GabayMo Admin` | |
| `SEED_PARTNER_EMAIL`, `SEED_PARTNER_NAME` | `partner@gabaymo.local`, `GabayMo Partner` | |
| `SEED_ADMIN_PASSWORD`, `SEED_PARTNER_PASSWORD` | empty | Set: the account is active with this password, no invite. |

Without a password the account is created **invited** (no password, cannot sign in). The seeder
queues the invitation email and prints the set-up link, so it works with `MAIL_MAILER=log` and no
queue worker:

```
Admin admin@gabaymo.local is invited. Set-up link (expires in 72h):
  http://localhost:3000/setup-account?token=...&email=admin%40gabaymo.local
```

Re-running the seeder while an account is still invited prints a fresh link (the old one stops
working). Accounts that are already set up are left alone.

### Migrations

One create migration per table group, no `Schema::table` alters (history was squashed before
launch). Change a table by editing its create migration and running `php artisan migrate:fresh --seed`
locally until the first production deploy; after that, add normal alter migrations.

## Tests

```bash
php artisan test
```

`phpunit.xml` runs on in-memory SQLite, so tests never touch the database in `.env`.
gabaymo-ai and PayMongo are faked with `Http::fake()`.

## Environment

See `.env.example` for every key with comments. The important ones:

| Key | Purpose |
|---|---|
| `APP_DEBUG` | `false` in production. |
| `SESSION_ENCRYPT`, `SESSION_SECURE_COOKIE`, `SESSION_DOMAIN` | Cookie session hardening (secure cookie needs HTTPS; set `false` locally). |
| `SANCTUM_STATEFUL_DOMAINS` | Frontend hosts that get cookie sessions. |
| `CORS_ALLOWED_ORIGINS` | Exact origins (comma list). `APP_URL` and `FRONTEND_URL` are always allowed; localhost ports only in local/testing. |
| `CORS_ALLOWED_ORIGIN_PATTERNS` | Optional regexes for origins you own. No wildcard by default. |
| `FRONTEND_URL`, `PARTNER_URL` | Links in emails (verification, reset, receipts, partner alerts). |
| `PAYMONGO_*` | Checkout + webhook secret (`PAYMONGO_ENV=sandbox|live`). |
| `GABAYMO_AI_URL`, `GABAYMO_AI_INTERNAL_KEY`, `GABAYMO_INTERNAL_KEY` | gabaymo-ai URL and the two signing secrets (one per direction). |
| `USAGE_*` | Token weights, hold size, `USAGE_RESERVATION_TTL_MINUTES` (stale-hold sweeper). |
| `RATE_LIMIT_*`, `ADMIN_LOGIN_MAX_ATTEMPTS`, `ADMIN_LOGIN_LOCK_MINUTES` | Throttling and staff lockout. |
| `TICKET_REPLY_HOURS` | Partner reply deadline (default 24). |
| `INVITE_EXPIRES_HOURS` | Lifetime of partner/staff set-up and password links (default 72). |
| `SEED_*` | Seeded admin and partner (see Seeding). |
| `MAIL_*`, `QUEUE_CONNECTION` | Mail is queued (`database` queue); `MAIL_MAILER=log` locally. |

## Scheduled commands (`routes/console.php`)

| Command | When | What |
|---|---|---|
| `usage:refresh` | hourly | Ends paid periods that are over (downgrade to the organization plan or Free Trial), then refreshes monthly usage (paid plans only inside the paid period). |
| `credits:sweep` | every 5 min | Releases AI usage holds gabaymo-ai never settled; a late finalize is still charged once. |
| `reminders:send` | hourly | Member reminders, the day before and on the due date (email + in-app). |
| `tickets:overdue` | hourly | Tells the assigned partner once when a ticket reply is past due. |
| `subscriptions:remind` | daily 09:00 | Renewal reminder emails 7 days and 1 day before the paid period ends. |
| `issuances:alert` | daily 07:00 | Asks gabaymo-ai for new issuances per member region (system actor) and notifies members with `issuance_alerts` on. |
| `subscriptions:expire` | manual | Same expiry step as `usage:refresh`. |

## Billing model

PayMongo checkout is a one-time payment. Each paid payment extends `users.current_period_end`
by the plan's interval (month or year); paying again for the current plan extends from the
current end. "Cancel" only stops reminders and lets the period end. At the end without a new
payment the member moves to their organization's plan (if active) or to the Free Trial with
its one-time allowance counted as used (extra usage is kept). Refunds take back the period
the payment bought. Webhooks are signature-checked and idempotent per event id
(`paymongo_webhook_events`). GabayMo emails its own receipts (`GM-OR-YYYYMM-000123`).

## Endpoints overview

All under `/api/v1`. Errors: `{ "message", "errors"? }`. Lists: `{ data, meta: { current_page, last_page, per_page, total } }`
with `?page=&per_page=&search=`.

- **Public**: `register` (requires `accept_terms`, optional `referral_code`), `login`, `session`,
  `password/forgot`, `password/reset`, `email/verify/{id}/{hash}`, `plans`, `paymongo/webhook`, `admin/login`, `admin/login/two-factor`,
  `invitations/{token}` (GET), `invitations/accept` (POST).
- **Member** (`member` middleware): `me` (GET/PUT/DELETE), `me/password`, `me/export`, `logout`, `logout-all`,
  `email/verification-notification`, `usage`, `payments`, `payments/checkout`, `payments/confirm`,
  `subscription/cancel|resume`, `redeem`, `reminders`, `tickets` (+ `/{ticket}/messages`, `/{ticket}/close`),
  `ai/ask`, `ai/conversations/{id}` (PATCH/DELETE), `ai/messages/{id}/feedback`, `ai/memory*`, `ai/*` (GET forward).
- **Partner** (`partner`): `partner/me|dashboard|tickets|accepted|profile|password|logout`,
  `partner/tickets/{ticket}/accept|messages|close`, `partner/ai/*`.
- **Shared** (`notifiable`): `notifications`, `broadcasting/auth`.
- **Admin** (`admin`, permission per module, default-deny): `admin/me` (+ `/password`, `/two-factor`),
  `overview`, `audit`, `members` (+ `suspend`, `unsuspend`, `plan`, `tokens`, `usage`), `partners`
  (+ `suspend`, `unsuspend`, `reset-password`, `resend-invite`), `roles` (+ `accounts`, `accounts/{admin}/reset-password`,
  `accounts/{admin}/resend-invite`), `subscriptions`,
  `payments` (+ `summary`), `tickets` (+ `close`), `access-keys`, `referral-codes`, `organizations` (+ `members`), `ai/*`.
- **Internal** (signed by gabaymo-ai): `internal/ai/credits/{id}/finalize|release`, `internal/ai/handoff-tickets`.

Suspended members/partners cannot sign in and their sessions are rejected.

### Partner and staff invitations

Staff never type another person's password. `POST admin/partners` and `POST admin/roles/{role}/accounts`
take name, email and the partner/role details, create the account as `invited` (no password, cannot sign
in) and queue an email with a **Set up your account** button to
`{PARTNER_URL or FRONTEND_URL}/setup-account?token=…&email=…` (partners use `PARTNER_URL` when set; staff
use `FRONTEND_URL`). Links are stored hashed in `account_invitations`, work once and expire after
`INVITE_EXPIRES_HOURS`. `resend-invite` replaces the link; `reset-password` emails a password link through
the same mechanism (`202`, no temporary password). Accepting sets the password, activates and verifies the
account and signs it in when possible. Details and response shapes: contract section D.

### Legacy

`AdminKnowledgeController` (`admin/knowledge-documents`) and `AdminInstructionController`
(`admin/service-instructions`) are kept for older admin builds only. Knowledge and service instructions
live in gabaymo-ai and are managed through `admin/ai/*` and `partner/ai/*`.
