# Atriom — the staging gate

**What a staging box is for here, how to bring one up, and how to tell it is right.**
Verified against the running code 2026-08-16.

> **Companion docs.** [INFRASTRUCTURE.md](INFRASTRUCTURE.md) provisions the box (nginx vhost, FPM
> pool, worker, cron, Cloudflare Access). [PRODUCTION-RUNBOOK.md](PRODUCTION-RUNBOOK.md) is the
> per-release sequence, which `./deploy.sh` runs unchanged on either environment.
> [GO-LIVE.md](GO-LIVE.md) is the gate for **real money** — none of it blocks staging.
> This file is the one thing those three do not answer: *is the staging box correct, and which of
> its red rows are supposed to be red?*

---

## 1. What staging is for — and the one decision it forces

Staging exists so the cut-over can be rehearsed before it is performed
([PRODUCTION-RUNBOOK.md §12](PRODUCTION-RUNBOOK.md) asks for it twice, with the same numbers both
times). That makes staging the box where a **wrong number is still cheap**, which is the whole of
its value — and the reason a fabricated payment on it is worse than harmless.

**Decide which staging you are running, because the two have opposite security postures:**

| | **A — demo data** (`migrate:fresh --seed`) | **B — production restore** |
|---|---|---|
| What is on it | `DemoSeeder`: a mall mid-life, fake tenants at `@*.test` | Real tenants, real leases, real money history |
| Seeded demo logins | Expected — password is published in `DEMO.md` | **Must be deleted or rotated.** Real data behind a known password |
| Forced 2FA | Optional | Same as production |
| Data-protection footing | None needed | The box now holds tax cards and signed leases |

[INFRASTRUCTURE.md §10](INFRASTRUCTURE.md) provisions **A** (`migrate:fresh --seed`), and
[§7](INFRASTRUCTURE.md) notes the nightly production backups "double as staging refresh data" —
which is **B**. Both are legitimate; they are not the same box. Pick one deliberately.

> **Recommended: start on A, and stay there until there is production data worth restoring.**
> Not because B is wrong — it is the better rehearsal, and the cut-over rehearsal is what staging is
> *for* — but because B's cost is paid on day one and its benefit arrives only after go-live. A
> restore box holds real tax cards, signed leases and money history; the moment it exists, the
> demo logins must go, 2FA must be forced, Cloudflare Access stops being optional, and the backup
> rows in §5 stop being "expected red". That is a second production to run, before there is a first.
>
> **Switch to B for the cut-over rehearsal itself**, when there is a real chart of accounts and real
> opening balances to rehearse against — and treat it as production from that day, including
> `SECURITY_FORCE_2FA_ROLES` and deleting the seeded logins. Before that point, A rehearses
> everything the code can rehearse: the deploy, the migrations, the billing run, the GL tie-out.

Either way, [INFRASTRUCTURE.md §8](INFRASTRUCTURE.md) puts staging behind **Cloudflare Access**
(email/OTP) so the hostname is not publicly reachable. On **B** that is not a nicety.

---

## 2. `.env` deltas from production

Start from [PRODUCTION-RUNBOOK.md §1](PRODUCTION-RUNBOOK.md), then change exactly these:

```
APP_ENV=staging                  # NOT production — see §3 for what this switches
APP_DEBUG=false                  # still false: staging is reachable, and stack traces name paths
APP_URL=https://staging.<domain>

MAIL_MAILER=log                  # or mailersend + MAIL_ALWAYS_TO — see below
MAIL_ALWAYS_TO=ops@…             # redirects EVERY outgoing mail; ignored on production by design

DEMO_PAYMENTS_ENABLED=           # LEAVE UNSET. Setting it makes the shortcut genuinely live here
ETA_MOCK=true                    # ETA has no sandbox that accepts staging documents
PAYMOB_ENABLED=false             # sandbox creds only, or off entirely

REDIS_PREFIX=atr_s_              # separate keyspace from prod (INFRASTRUCTURE.md §5)
REDIS_DB=1
REDIS_CACHE_DB=3
CACHE_STORE=redis                # all three on Redis, same as production — see §3
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

BACKUP_DISKS=backups             # staging is disposable on posture A; on posture B, treat as prod
```

**Mail is the one with teeth.** Demo data is full of `@*.test` addresses. Pointed at a live
provider they hard-bounce, burn the sending quota and cost the domain reputation. `MAIL_ALWAYS_TO`
redirects every message to one inbox and is **ignored when `APP_ENV=production`**, so it cannot
leak from staging into prod behaviour. `MAIL_MAILER=log` keeps mail on the box entirely.

**`DEMO_PAYMENTS_ENABLED` is the sharpest edge on this page.** On production the flag is overridden
outright — `DemoPayments::forbiddenByEnvironment()` refuses regardless. **On staging nothing
overrides it.** Set it, and an authenticated tenant can `POST /api/v1/me/invoices/{id}/pay-demo`
and mark their own invoice paid: a real captured `Payment`, `Dr Bank / Cr AR`, and
`billing:reconcile` stays **green** throughout because every internal relationship really is
consistent — the money simply never existed. That is precisely the rehearsal you came here to
trust. Since 2026-08-16 `atriom:health` **fails** while it is live rather than reporting it as
expected.

---

## 3. What `APP_ENV=staging` actually switches

Staging is not a bigger laptop and not a smaller production. `App\Support\Deployment` sorts every
environment into one of three tiers, and an environment nobody anticipated (`uat`, a pilot name)
inherits the **stricter** treatment rather than the laxer one:

| | `local` / `testing` | **`staging`** (and any other name) | `production` |
|---|---|---|---|
| Demo-payment shortcut | on by default | **off unless explicitly opted in — and health fails if it is** | refused outright |
| Seeded demo logins | expected | **health fails** | health fails |
| Forced 2FA unset | expected | **health fails** | health fails |
| Chart of accounts unmapped | expected | **health fails** | health fails |
| Mobile reset URL unset | not checked | **health fails** | health fails |
| cache/session/queue on `database` | not checked | **health fails** | health fails |
| `MAIL_ALWAYS_TO` honoured | yes | yes | **ignored** |
| Backups must leave the box | not checked | health fails | health fails |

> **Corrected 2026-08-16.** Three of those rows used to read "not checked" on staging. `Health` had
> two spellings of "is this a real box?" — one counting staging as production, one counting it as a
> laptop — and both read as *is this production?*. On the only two environments anybody had built
> they agree, so it never surfaced. The mobile-reset, runtime-driver and demo-payment checks took
> the second spelling and went silent on exactly the box the operator spins up to rehearse a
> cut-over. One reading now (`App\Support\Deployment`), pinned by
> `StagingHealthIsNotSilentTest`, which pairs every refusal with a control that must pass and a
> workstation case that must stay quiet.

---

## 4. Bring-up

> Ordered end-to-end, across this document, `GO-LIVE.md` and the QA gates:
> **[STAGING-CUTOVER.md](STAGING-CUTOVER.md)**. This section is step 2 of eight.

Provision the box per [INFRASTRUCTURE.md §10](INFRASTRUCTURE.md), then:

```bash
php artisan key:generate
php artisan migrate:fresh --seed          # posture A. Posture B: restore the dump, then `migrate --force`
php artisan atriom:install --admin-email=you@example.com   # posture B only — A already has admins
npm ci && npm run build                   # app assets AND the handbook; both panels are unstyled without it
php artisan filament:assets
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan storage:link
php artisan db:seed --class='Database\Seeders\RolesPermissionsSeeder' --force   # see below
php artisan atriom:rebuild-search         # posture B only — see below
php artisan atriom:preflight              # health + both data audits + the books reconciliation, in order
```

**Re-seed the roles on EVERY upgrade.** A release that adds a screen adds its permissions to
`RolesPermissionsSeeder`, and **nothing applies that but running the seeder**. The tests seed the
catalogue themselves, so a missing permission is invisible to a green suite — and the symptom is not
an error: `canAccess()` simply returns false and the screen is **absent from the navigation for
everyone, including super_admin**. That is exactly how the Trades and Failure-code registers shipped
invisible on 2026-08-20, and it took the operator opening the panel to find it. The seeder is
idempotent (it bulk-writes the catalogue and re-syncs every role grant), so running it on every
deploy costs nothing and removes the question.

**`atriom:rebuild-search` after an UPGRADE, not after a fresh install.** The fold blob is written by
the model on save, so `migrate:fresh --seed` produces correct blobs and the command is a no-op.
Restoring an existing database and running `migrate --force` does **not** re-fold anything — a
migration that changes what a model contributes to its blob leaves every existing row stale, and the
failure is silent: the search bar reports the record does not exist. The 2026-08-20 trade register is
exactly that case (`category` left the work-order, plan and equipment blobs when it stopped being a
column on the row). Rule of thumb: if a release changes any `searchTextSources()`, this command is
part of the release.

> After a **restore** (posture B) run `atriom:preflight --sync` first — it backfills ledger entries
> for the documents the restore brought in — then run it again **read-only**. The second pass is the
> gate: a preflight that only passes while repairing is not one.

Every release after that is **`./deploy.sh`**, which runs the runbook's sequence and deploys
staging without the production confirm prompt.

---

## 5. Reading `atriom:health` on a correct staging box

A freshly provisioned staging box is **degraded**, and most of it is honest. Work down the FAIL
rows in this order:

| Row | On a correct staging box | If it is red |
|---|---|---|
| `database` `cache` `queue` `storage` | **OK** | Real fault. Stop here. |
| `accounting` | **OK** | The chart/mappings never seeded — `atriom:install`. Nothing can post. |
| `admin_access` | **OK** | Nobody holds `super_admin`; the login page will simply reject everyone. |
| `mobile_reset_url` | **OK** | Set `APP_MOBILE_RESET_URL`, or mobile reset mail 404s for every tester. |
| `runtime_drivers` | **OK** | Still on the `database` driver — staging is then not rehearsing the production topology. |
| `demo_payments` | **OK** | `DEMO_PAYMENTS_ENABLED` is set. Unset it. See §2. |
| `scheduler` | red until cron is installed, then **OK** | A dead scheduler silently takes billing, GL sync and backups with it. |
| `two_factor` | red unless `SECURITY_FORCE_2FA_ROLES` is set | **Expected on posture A. Not acceptable on posture B.** |
| `demo_accounts` | red on posture A | **Expected on A** (that is what `DemoSeeder` is). **On B, delete or rotate them.** |
| `backups` `backup_capability` | red unless staging is backed up | Expected on A. On B the box holds real data. |

> **Do not point an external uptime monitor at staging's `/health` on posture A.** It is red by
> design there, and a monitor that is always red is a monitor nobody reads — including the
> production one beside it. Run `atriom:health` by hand after a deploy instead; `./deploy.sh`
> already does, and reports rather than fails on it.

---

## 6. What staging cannot tell you

Recorded so nobody reads a green staging box as a green production one:

- **ETA is mocked.** No document staging produces has been accepted by the Egyptian Tax Authority.
  There is no sandbox that would accept one. *(ETA work is on hold by the owner's instruction; this
  records the limit, it does not ask for work.)*
- **Paymob is sandbox or off.** A real card has never been charged.
- **The test suite runs on sqlite `:memory:`, staging runs MySQL.** The two have disagreed before —
  sqlite silently drops `CHECK` constraints on any `->change()`, which is how 24 columns were enums
  in production and unconstrained in tests while a gate reading the test schema passed. A green
  suite is not evidence about MySQL; a successful `migrate --force` on staging is.
- **Backups are unproven until restored.** `backup:monitor` only checks an archive exists.
  `atriom:backup-verify` replays the newest one into a scratch DB — that is the only evidence.
- **Opening balances are not loaded**, so no trial balance on staging includes the history that
  precedes cut-over ([GO-LIVE.md §2, A3.7](GO-LIVE.md)).

---

## 7. Verified 2026-08-16

Run against a scratch MySQL 8.0 database with `APP_ENV=staging`, on the code at the time of
writing. Reproduce with the §4 sequence.

- `migrate` — clean, no failures, on **MySQL 8.0.33** (the suite only ever exercises sqlite).
- `atriom:install` — seeds roles, the approval ladder, departments, the chart, the posting map,
  charge codes and the fiscal calendar; mints one `super_admin`; reports that it cannot back itself
  up. Verdict: **ready to post**.
- `npm run build` — app assets and the handbook, EN and AR at parity. The handbook datasets
  regenerated with **no drift** from the registries.
- `config:cache` + `route:cache` + `view:cache` + `event:cache` — all succeed, and `atriom:health`
  still reads correctly under a cached config (the trap being `env()` returning null there).
- Serving under those caches: `/admin/login` 200 · `/portal/login` 200 · `/api/v1/me` **401** (no
  existence enumeration) · `/handbook` 302 to auth · `/health` 503 with `{"status":"degraded"}` and
  **no detail** to an anonymous caller (detail needs `HEALTH_TOKEN`).

**The one thing this machine could not verify:** `mysqldump` is not on its PATH, so
`backup_capability` fails here exactly as [GO-LIVE.md §1.1](GO-LIVE.md) describes. Ship the MySQL
client in the deploy image and re-check on the box itself.
