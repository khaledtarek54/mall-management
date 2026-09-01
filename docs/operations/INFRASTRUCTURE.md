# Atriom — Infrastructure (staging + production)

How the servers are provisioned and wired. This is the **infrastructure** layer;
for per-release deploy steps + ops procedures see **[PRODUCTION-RUNBOOK.md](PRODUCTION-RUNBOOK.md)**
(this doc supersedes a few of its env defaults — see [§Env deltas](#env-deltas-vs-the-runbook)).

For the **staging box specifically** — what it is for, its `.env` deltas, and which of its red
`atriom:health` rows are expected — see **[STAGING.md](STAGING.md)**.

**Design goals (chosen with the owner):** lowest cost to start · one self-managed box we
control end-to-end (full root, install anything) · staging + production co-located to avoid
complexity · Cloudflare in front for protection · a clean scaling/offload path documented so
growth is copy-paste, not a rebuild.

---

## 1. Architecture at a glance

```
                            Internet
                               │
                    ┌──────────▼───────────┐
                    │      Cloudflare       │  WAF · DDoS · TLS · rate-limit
                    │   (free plan + Tunnel)│  origin IP never exposed
                    └──────────┬───────────┘
                    outbound tunnel (no open inbound ports)
                               │
        ┌──────────────────────▼───────────────────────────┐
        │  Contabo Cloud VPS 4 (Ubuntu 24.04 · 8GB/4vCPU · DE)│
        │                                                    │
        │  cloudflared ─┬─ app.<domain>    → 127.0.0.1:8080  │  prod  vhost
        │               └─ staging.<domain>→ 127.0.0.1:8081  │  stg   vhost
        │                                                    │
        │  nginx  ·  PHP 8.4-FPM (pool-prod / pool-staging)  │
        │  Redis  (cache + queue + sessions, local socket)   │
        │  systemd queue workers (per env)  ·  cron scheduler│
        └───────┬───────────────────────────────┬───────────┘
                │ TLS-required + IP-allowlist    │ S3 API / SFTP
                │ (~15ms, cross-provider)        │
   ┌────────────▼───────────┐        ┌───────────▼──────────────────┐
   │ Aiven Managed MySQL 8.4│        │ Hetzner Object Storage (S3)   │  app files (uploads, PDFs)
   │  (Amsterdam · TLS req.) │        │ Hetzner Storage Box (restic)  │  nightly DB + media backups
   │  ├ atriom_prod (prod_u)│        └───────────────────────────────┘
   │  └ atriom_staging (stg)│  ← paid plan; free tier = single defaultdb
   └────────────────────────┘
```

**Why MySQL is off-box, but Redis is on-box:** the owner chose managed MySQL so the app box
spends no resources on DB engine work. The trade-off is that MySQL is now a network hop away
(~15 ms per query from Hetzner DE ↔ Aiven Amsterdam — see [§6](#6-managed-mysql-aiven-amsterdam)).
To keep that hop off the hot path,
**cache/queue/sessions run on local Redis** — only real relational data crosses the network.
Leaving those on the `database` driver (the repo default) would send every job poll, cache read,
and session lookup to the managed DB. **Non-negotiable in this topology** (see [§5](#5-redis--driver-overrides-required)).

---

## 2. Components, sizing & cost

§1 is the **finished** shape. It does not have to be bought at once, and on a first staging box most
of it should not be: every managed component in it exists to protect data a posture-A staging box
does not have yet ([STAGING.md §1](STAGING.md)). Three phases, each additive — nothing below is
rebuilt at the next step, only moved off the box.

*Prices verified August 2026 and they drift — Hetzner adjusted on 15 June 2026. Re-check at signup.*

### Phase 1 — staging only (~$6/mo) — **BOUGHT AND BUILT 2026-08-30**

| Component | Spec | ~Monthly |
|---|---|---|
| **Contabo Cloud VPS 4** | 4 vCPU AMD EPYC / 8 GB / 100 GB SSD, unlimited traffic | **$6.02** inc. VAT ($5.28 ex.) |
| MySQL **8.4 LTS** | **on the box** | $0 |
| Redis | on the box — not optional, see [§5](#5-redis--driver-overrides-required) | $0 |
| Uploads + backup archives | the box's own disk | $0 |
| Cloudflare | Free plan + Tunnel + Access on the staging hostname | $0 |
| Domain | at Cloudflare | ~$10 / yr |
| **All-in** | | **~$6.02/mo** |

**The rate is promotional for 24 months and reverts to $7.52.** Diarise it. The equivalent warning
applies to every provider in [§2.1](#21-why-this-provider-and-what-was-rejected) — check the
*renewal*, never the headline.

**Auto Backup is a paid Contabo add-on and is currently OFF.** That is the one consequence of this
choice that has to be carried rather than argued with: on a box where the database, the uploads and
the archives share a disk, there is now **no** copy anywhere else. Acceptable while the data is
`DemoSeeder` output and on nothing else — so either switch the add-on on, or treat
[§7](#7-backups)'s off-box copy as arriving on the same day the first real record does, not later.

**4 GB of swap, added.** At 8 GB it is insurance rather than the requirement it would be at 4:
`npm run build` compiles the app assets *and* the VitePress handbook, and an OOM there leaves both
panels serving unstyled HTML — the same symptom as a skipped `npm run build`, so it is diagnosed as
one. `vm.swappiness=10`, and `innodb_buffer_pool_size = 1G`.

**8 GB at the phase-1 price is why this box needs no resize in phase 2** — it is already the size
[§2](#2-components-sizing--cost) wanted for prod+staging sharing one machine.

**MySQL 8.4, not Ubuntu's 8.0.** `apt install mysql-server` on Ubuntu 24.04 gives **8.0.46**, and
the phase-2 destination is a managed **8.4** node — staging on a different major than production
rehearses something else, and the cheapest moment to fix it is an empty box. It comes from Oracle's
repo, and **the signing key published as `RPM-GPG-KEY-mysql-2023` is EXPIRED**: apt then rejects the
repo with `EXPKEYSIG` and *silently falls back to Ubuntu's 8.0*, which looks like a successful
install. The same key re-issued with a longer expiry is published as `RPM-GPG-KEY-mysql-2025` — use
that one, and check `mysqld --version` afterwards rather than trusting the apt exit code.

### Phase 2 — production joins the same box (~€30–40/mo)

The box becomes two environments per [§3](#3-one-box-two-environments-native-separation). On VPS-1
that needs **no resize** — 8 GB is already the target size, so the step is the second system user,
FPM pool, vhost, worker, cron and tunnel ingress. Then move off the box the three things whose loss
is now unacceptable:

| Add | ~Monthly | What it buys |
|---|---|---|
| Managed MySQL — Aiven paid node, or DO Managed MySQL FRA1 | **~$15–25** | A standby + PITR, and the two-user grant wall in [§3](#the-db-safety-wall-critical) that makes a misfired `migrate:fresh` unable to reach production |
| Object storage, bucket per env | **~€6** | `FILESYSTEM_DISK=s3` — uploads survive the box |
| Off-box backup target (Storage Box + restic) | **~€4** | `BACKUP_DISKS="backups,s3"`; a second failure domain |

**Do not defer this past the day real data lands.** In phase 1 the database, the uploads and the
archives are all on one disk, so anything that takes the box — hardware, or the account problems in
[§2.1](#21-why-this-provider-and-what-was-rejected) — is total loss. That is correct for demo data and
indefensible for a tenant's signed lease.

### Phase 3 — split, when something actually pushes

See [§11](#11-scaling--offload-path-designed-in-used-later). Nothing here is scheduled; each row is
a response to a measured pressure, not a milestone.

### 2.1 Why this provider, and what was rejected

**Hetzner was the pick until 2026-08-30 and is not, because you cannot buy it.** All eight
cost-optimized **CX** plans are out of stock in every location — the supply constraint that started
in June 2026, not an account problem. Recorded because the *shape* of that finding outlives this
particular shortage: the cheapest line at a provider is the first to be rationed, and a plan that
cannot be purchased is not a cheaper plan. Check availability **before** designing around a SKU.

Hetzner is not closed, though — **CX is one of four lines.** `CAX` (ARM/Ampere) is separate and
EU-available: **CAX21**, 4 vCPU / 8 GB, **€7.99**. It remains the fallback if OVH disappoints, and
this stack runs on ARM without qualification — PHP 8.4 and all nine required extensions, MySQL,
Redis and Node all ship arm64 builds, and mpdf is pure PHP. The only reason it is second is that it
costs more than VPS-1 for the same specs while adding an architecture variable.

**What VPS-1 buys beyond the price:** daily backups are **included**, which is a partial answer to
phase 1's worst property — database, uploads and archives on one disk. Partial, and do not overread
it: a provider snapshot sits in the same failure domain as the thing it snapshots, so it covers
"someone truncated a table", not "the account or the region is gone". [§7](#7-backups) is still the
real answer the day real data lands.

**OVH's own history is on the record and belongs here:** the 2021 Strasbourg fire destroyed SBG2,
and the customers who lost data were the ones who had assumed the provider's backups were theirs.
Frankfurt is a different site, and the lesson is the one [§7](#7-backups) already states rather than
a reason to avoid the provider.

Whatever the provider, three commercial risks apply and each has a cheap mitigation. **Two land
specifically on an Egypt-based buyer** — take all three:

| Risk | Mitigation |
|---|---|
| **Suspension on an unpaid invoice.** An Egyptian company paying a European provider by card meets international declines, FX limits and bank blocks routinely, and the consequence is a stopped box rather than a dunning email | **Prepay and keep a balance.** Second card on file. A *monitored ops mailbox* on the account, never a personal address |
| **Capacity rationing.** What removed Hetzner CX here, and no provider is immune | **Provision now**, while it is only staging. Holding the box before production day is worth more than the monthly saving |
| **A managed service degrading for weeks** — a Hetzner Object Storage incident in hel1 took ~31 days to fully mitigate in H1 2026 | Never let one product hold the **only** off-box backup copy. [§7](#7-backups)'s failure-domain rule, applied to the vendor |

**Phase 2's storage is provider-independent** and does not have to follow the app box: the `s3` disk
takes any S3-compatible endpoint (Hetzner, Backblaze B2, Cloudflare R2, OVH Object Storage) and the
restic target any SFTP host. Buying them from a *different* vendor than the app box is the stronger
arrangement, not a compromise.

> **Superseded 2026-08-30: the operator bought Contabo Cloud VPS 4 and the box is built.** The
> analysis below stands as the record of the trade — it was a close call on equal money, and the two
> reasons it went the other way are now **live obligations rather than arguments**: the backup gap is
> carried in [Phase 1](#phase-1--staging-only-6mo--bought-and-built-2026-08-30) above, and the disk
> class is something to *measure* on the real box rather than assume, since the panel's 130–400
> queries a page is where it would show. Nothing else in this document changes — the phasing, the
> hardening and the gates are provider-independent by design.

**Contabo — rejected, and NOT on price. Corrected 2026-08-30 from the vendor's own page.** An
earlier revision of this section said Cloud VPS S was ~$11.31 "for the same CPU and RAM" and
rejected it as *not cheaper*. That figure came from a third-party comparison and was wrong: **Cloud
VPS 4 is $6.02/mo inc. VAT ($5.28 ex.) for 4 vCPU / 8 GB / 100 GB**, marginally *under* VPS-1 and
with more disk. The correction is recorded rather than quietly edited because a price argument is
the easiest kind to make and the easiest to be stale about, and it was doing the rhetorical work
here for a conclusion that has a better reason behind it.

The real reasons, both of which survive the price being equal:

- **Disk class.** VPS-1 is NVMe; Cloud VPS 4's base tier is SSD (NVMe is an upsell). Phase 1 puts
  MySQL on that disk and a Filament list page issues 130–400 queries
  ([§11](#11-scaling--offload-path-designed-in-used-later) measures it), so this is the workload's
  most sensitive axis. Contabo's documented disk-latency and CPU-steal variance does not make pages
  uniformly slower — it makes them *randomly* slow, which is the complaint nobody can reproduce.
- **Backups.** VPS-1 includes daily backups; on Contabo *Auto Backup* is a paid add-on. Switch it on
  — and in phase 1, with the database, uploads and archives on one disk, you must — and Contabo is
  the **more** expensive of the two while still being SSD.

Two things to note fairly: Contabo is a real company (Munich, 2003) carrying 4.6/5 across ~11k
Trustpilot reviews, and its headline is a **24-month** rate that reverts to $7.52 — the same
renewal-price trap flagged above for OVH, but stated openly on their own page, which is more than
most do.

**IONOS** (VPS Linux L — 4 vCPU / 8 GB / 240 GB, ~$15) is the sane German alternative if OVH's
renewal price disappoints: more disk, unlimited traffic, a conventional business vendor. **If a
payment-related suspension is unacceptable to the operator**, the answer is DigitalOcean Frankfurt —
roughly 3× all-in, and it deletes the Aiven vendor by putting managed MySQL in the same console.
Both are defensible purchases, not downgrades of this plan.

**Not yet answered: data residency.** This system holds Egyptian tenants' tax cards, national IDs,
signed leases and employee payroll, and Egypt's PDPL has cross-border transfer rules. Hosting in
Germany or France may be fine or may need consent or licensing — a question for the operator's
counsel, far cheaper to ask before the box exists than after real data is on it.

---

## 3. One box, two environments (native separation)

No containers. Each environment is an independent tenant of the same OS, separated by
**dedicated system user + directory + PHP-FPM pool + nginx vhost + Redis prefix + DB user +
worker + cron**. Isolation is enforced by config discipline; the hard safety wall is the DB grant.

```
system users:   atriom-prod          atriom-staging
code:           /var/www/atriom-prod/current   /var/www/atriom-staging/current
env file:       .../shared/.env      (APP_ENV=production | staging)
nginx:          app.<domain> :8080   staging.<domain> :8081   (both bind 127.0.0.1 only)
php-fpm pool:   pool-prod (12 kids)  pool-staging (4 kids)    ← caps stop staging starving prod
redis:          DB 0 + prefix atr_p  DB 1 + prefix atr_s      ← distinct keyspaces
mysql:          atriom_prod/prod_u   atriom_staging/stg_u     ← stg_u has ZERO grant on prod DB
worker:         atriom-worker-prod   atriom-worker-staging    (systemd)
cron:           schedule:run (prod)  schedule:run (staging)   (per-user crontab)
```

### The DB safety wall (critical)

`CLAUDE.md` runs `php artisan migrate:fresh --seed` as a normal workflow step. On a shared box a
wrong `.env` could otherwise **drop production**. Prevent it at the database, not by care:

```sql
CREATE DATABASE atriom_prod;      CREATE DATABASE atriom_staging;
CREATE USER 'prod_u'@'%' IDENTIFIED BY '…';   GRANT ALL ON atriom_prod.*     TO 'prod_u'@'%';
CREATE USER 'stg_u'@'%'  IDENTIFIED BY '…';   GRANT ALL ON atriom_staging.*  TO 'stg_u'@'%';
-- stg_u has NO privilege on atriom_prod → a misfired migrate:fresh physically cannot touch prod.
```

Never grant either user cross-database access. `migrate:fresh --seed` is for **staging only**; prod
follows the runbook (`migrate --force`, no `DemoSeeder`).

### nginx vhost (prod; staging identical on :8081)

```nginx
server {
    listen 127.0.0.1:8080;                       # Cloudflare Tunnel reaches this on localhost
    server_name app.<domain>;
    root /var/www/atriom-prod/current/public;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/atriom-prod.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
```

### PHP-FPM pool (prod; staging = smaller caps so it can't starve prod)

```ini
; /etc/php/8.4/fpm/pool.d/atriom-prod.conf
[atriom-prod]
user = atriom-prod
group = atriom-prod
listen = /run/php/atriom-prod.sock
listen.owner = www-data          ; nginx worker user
listen.group = www-data
pm = dynamic
pm.max_children = 12             ; staging pool: 4
pm.start_servers = 3
php_admin_value[memory_limit] = 256M
```

### Queue worker (systemd, per env) — money flows depend on it

```ini
# /etc/systemd/system/atriom-worker-prod.service
[Service]
User=atriom-prod
WorkingDirectory=/var/www/atriom-prod/current
ExecStart=/usr/bin/php artisan queue:work redis --tries=3 --max-time=3600 --sleep=3
Restart=always
StartLimitIntervalSec=0
[Install]
WantedBy=multi-user.target
```
`systemctl enable --now atriom-worker-prod atriom-worker-staging`. (The runbook's supervisor recipe
also works — pick one. On redeploy, `php artisan queue:restart` lets the workers pick up new code.)

### Scheduler (cron, per env)

```cron
# crontab -u atriom-prod   (and again for atriom-staging)
* * * * * cd /var/www/atriom-prod/current && php artisan schedule:run >> /dev/null 2>&1
```
Drives every cadence in `routes/console.php`: `requests:scan-sla-breaches` (hourly),
`billing:scan-overdue-invoices`/`remind-overdue-tenants` (06:00/06:15), `billing:apply-late-fees`,
`cam:reconcile`, `accounting:post-depreciation`/`sync-ledger`, `requests:auto-close`/
`generate-preventive`, `vendors:expire-contracts`, `marketing:ensure-budgets`, `atriom:prune-activity-log`.
Set `APP_TIMEZONE=Africa/Cairo` so cadences fire on Cairo wall-clock.

---

## 4. Cloudflare Tunnel (the only public entry)

No inbound ports open on the VPS. `cloudflared` dials **out** to Cloudflare and receives traffic
for both hostnames. Cloudflare terminates public TLS; the origin serves plain HTTP on loopback.

```bash
cloudflared tunnel login
cloudflared tunnel create atriom
cloudflared tunnel route dns atriom app.<domain>
cloudflared tunnel route dns atriom staging.<domain>
```

```yaml
# /etc/cloudflared/config.yml
tunnel: <TUNNEL-ID>
credentials-file: /etc/cloudflared/<TUNNEL-ID>.json
ingress:
  - hostname: app.<domain>
    service: http://localhost:8080        # prod nginx vhost
  - hostname: staging.<domain>
    service: http://localhost:8081        # staging nginx vhost
  - service: http_status:404
```
`cloudflared service install && systemctl enable --now cloudflared`.

**Real client IP:** traffic reaches nginx from `127.0.0.1` (cloudflared) with the true client in
`CF-Connecting-IP` / `X-Forwarded-For`. Set Laravel `TrustProxies` to trust the loopback so
`request()->ip()`, throttling, and audit logs record the real visitor — not `127.0.0.1`.

**Edge protection to enable (free):** WAF managed ruleset · rate-limiting on `/admin` and
`/api/v1` login routes · "Under Attack" mode toggle.

> **Access was set up on 2026-08-30 and then REMOVED, at the operator's instruction** — it puts a
> second sign-in (Cloudflare one-time PIN) in front of the panel's own, and the round trip was not
> wanted for day-to-day staging work. **Cloudflare Turnstile on the admin login carries the load
> instead** (`App\Support\Turnstile`): it is a challenge rather than an identity check, so it stops
> automated credential stuffing and does NOT make the hostname unreachable to a person who finds it.
>
> State the residual plainly rather than implying the two are equivalent: **the staging URL is now
> publicly reachable**, and what stands between it and a stranger is the Filament login, the rate
> limiter and Turnstile. That is acceptable on posture A — demo data, and the seeded accounts on this
> box do NOT use the published `DEMO.md` password — and it is **not** acceptable on posture B, where
> the same box holds real tax cards and signed leases. Re-instate Access before any restore.
> Re-creating it is one API call plus one policy; the app needs no change either way.

The paragraph the Access recommendation used to sit in:
(email/OTP) so the staging hostname isn't publicly reachable at all.

---

## 5. Redis + driver overrides (REQUIRED)

Because MySQL is off-box, move chatty state to local Redis. Per-env `.env` deltas vs the repo
defaults / the runbook:

```
CACHE_STORE=redis          # was: database
SESSION_DRIVER=redis       # was: database   (keep SESSION_ENCRYPT=true in prod)
QUEUE_CONNECTION=redis     # was: database
REDIS_CLIENT=phpredis      # install the ext-redis PHP extension (not predis)
REDIS_HOST=127.0.0.1
REDIS_PREFIX=atr_p_        # prod;  atr_s_ on staging   → separate keyspaces
REDIS_DB=0                 # prod;  1 on staging
REDIS_CACHE_DB=2           # prod;  3 on staging
```
Only DB-backed relational data now leaves the box. After changing drivers, re-run
`php artisan config:cache`.

> **Phase 1 note (2026-08-30): this section argues for Redis purely on the network hop, and in
> phase 1 there ISN'T one** — MySQL is on the box. The reasons that still hold are different ones,
> worth stating so nobody reads the hop disappearing as permission to go back to `database`:
> sessions, queue polling and cache reads would otherwise compete with the app's own queries for
> one 1 GB buffer pool and one disk; and the monthly-billing double-bill guard is a
> **`Cache::lock`** (`MonthlyBillingService`, 900s — one of 14 in `app/`), so on the `database`
> driver the lock would live inside the very instance it is arbitrating writes against.
> `maxmemory-policy` must stay **`noeviction`** for that reason — under `allkeys-lru` Redis can
> evict a lock key mid-run and the guard silently stops guarding.
>
> **THE PHASE-2 ITEM: turn on AOF before production shares this box.** Redis ships
> `appendonly no` with `save 3600 1 …`, so a restart can lose up to an hour of queued jobs — and
> `RunMonthlyBilling` and `ApplyLateFees` are scheduled as **jobs, not commands**, so a lost entry
> is a billing run that silently never happened. Deliberately NOT done on staging, where the data
> is `DemoSeeder` output and nothing is at stake; recorded here because this box becomes production
> and an "it's only staging" default is otherwise inherited without anyone deciding.
> `appendonly yes` + `appendfsync everysec` narrows the window from an hour to a second.

---

## 6. Managed MySQL (Aiven, Amsterdam)

> **Phase 1 runs MySQL ON the box and this whole section waits.** A deliberate deviation from the
> topology in §1, stated rather than implied: a posture-A staging box holds demo data, so the
> standby and PITR a managed node exists to provide are protecting nothing. **What the deviation
> costs** is that staging then never rehearses the real connection path — TLS-required, a
> non-3306 port, `MYSQL_ATTR_SSL_CA`, and ~15 ms of network per query — which is exactly what the
> cut-over rehearsal is for. So phase 2 moves **both** environments onto the managed service, not
> production alone; a staging box on a different DB topology than production is a rehearsal of
> something else. One thing gets *harder* at that moment and is easy to miss: `mysqldump` is on
> the box for free today and is not there once MySQL leaves, so `apt install mysql-client` (or
> `DB_DUMP_BINARY_PATH`) becomes a phase-2 step or `backup_capability` goes red and no backup is
> ever taken. [STAGING-CUTOVER.md §1](STAGING-CUTOVER.md) records that this has failed on every
> box so far.

**Provisioned:** Aiven for **MySQL 8.4**, region *Europe – Netherlands (DigitalOcean: Amsterdam)*,
single node. ~15 ms to Hetzner DE — a touch further than Frankfurt but well within tolerance.
Endpoint is a host + non-standard port (e.g. `:18332`) with **TLS required** (`ssl-mode=REQUIRED`).

> **Free tier = staging/testing only.** Single node, no HA, limited storage/connections. For
> **production** move to a paid Aiven plan (or DigitalOcean Managed MySQL, FRA1) so you get a
> standby node + PITR backups. DO is the drop-in prod-grade alternative; connection steps are identical.

**TLS is mandatory, and the CA cert is not optional.** `config/database.php` already wires
`MYSQL_ATTR_SSL_CA` into the `mysql` PDO options. If that env var is empty, `array_filter` drops it
and PDO connects *without* SSL → Aiven rejects the handshake. So:

1. Aiven console → service → **CA certificate → download** → save to `storage/certs/aiven-ca.pem`
   (dir is gitignored).
2. Per-env `.env`:
   ```
   DB_CONNECTION=mysql
   DB_HOST=<service>.aivencloud.com
   DB_PORT=<port>                 # Aiven uses a non-3306 port
   DB_DATABASE=defaultdb          # or a dedicated db per env once past the free-tier test
   DB_USERNAME=avnadmin
   DB_PASSWORD=<from console>
   MYSQL_ATTR_SSL_CA=/abs/path/storage/certs/aiven-ca.pem
   ```
3. Verify: `php artisan db:show` (connects + lists tables) before migrating.

**Trusted sources:** in the Aiven console, restrict the service's allowed IPs to the app box's
public IP (and your workstation IP while testing). **DB isolation** ([§3](#the-db-safety-wall-critical))
still applies — on a paid plan create `atriom_prod` / `atriom_staging` with separate users; the free
tier's single `defaultdb` is fine only for connectivity testing.

Aiven takes managed backups on paid plans; we **still** run independent dumps ([§7](#7-backups)) —
different failure domain, and they double as staging refresh data.

---

## 7. Backups

> **Phase 1 is `BACKUP_DISKS=backups` — local only — and that is acceptable for exactly one
> reason: posture A holds demo data.** A copy on the same disk as the database is not a backup, it
> is a second file that dies with the box. The moment real data lands, the off-box copy below is
> not a hardening step, it is the difference between an incident and a closure. Put that copy on a
> **different vendor or a different system** from the app's object storage — [§2.1](#21-why-this-provider-and-what-was-rejected)
> records a month-long Object Storage degradation, and a backup sharing a failure domain with the
> thing it backs up is the same mistake as keeping it on the same disk.

Two layers. The **in-app layer now exists in code**; the off-box layer below is still an
operator task.

### In-app (implemented) — `spatie/laravel-backup`

Runs from the **scheduler** (`routes/console.php`), not from a CI/deploy workflow: a backup
is a runtime concern, not a build one — it must keep running between deploys and on a box CI
never touches. The scheduler already keeps billing and the GL current, so a backup rides on
infrastructure whose failure is independently visible.

| When | Command | Why |
| --- | --- | --- |
| 01:00 daily | `backup:clean` | Applies retention *before* the new archive is written — a full disk is how a backup run fails. |
| 01:15 daily | `backup:run` | DB dump + uploads. |
| 07:30 daily | `backup:monitor` | **The check that matters.** `backup:run` failing is loud; a backup job that silently *stopped* is not — and that is the state you discover on the day you need to restore. Fails when the newest archive is older than a day, on every destination. |

**What is in the archive:** the database, plus `storage/app` — signed leases, tenant tax
cards, vendor COI/CR documents, sales reports (every `useDisk('local')` collection) and
per-property branding. **Not** the codebase: it is in git and redeployable, and including it
makes archives large enough that retention becomes the thing that quietly gets cut.

**Where archives go:** the `backups` disk (`storage/backups`), deliberately outside
`storage/app`. Two reasons, both learned the hard way: a destination inside the backup
*source* means every run sweeps in the previous archives, and the `local` disk is
`serve => true` — a dump containing tax cards has no business on a disk the app can serve
from. `storage/backups` is git-ignored.

> **`BACKUP_ARCHIVE_PASSWORD=` empty used to mean NO BACKUP AT ALL, not "no encryption"** (found
> and fixed 2026-08-30 on the first real box). Empty is not null, spatie enables AES on any non-null
> value, and libzip then refuses at `close()` — after the dump has run and the zip is full, so the
> operator sees `ZipArchive::close(): Invalid argument`, which reads as a corrupt file rather than a
> missing setting. Every `backup:run` failed, `--only-db` with a single file included.
> `config/backup.php` now coerces it, pinned by `EmptyBackupPasswordDoesNotKillTheBackupTest`. The
> reason it went unnoticed for so long is the same reason [STATUS §1.1](../STATUS.md) records a
> nineteen-day outage: a backup that never runs produces no error anybody reads.

**Before go-live** (see `.env.example`):
- `BACKUP_DISKS="backups,s3"` — a single copy on the same box as the database is **not a
  backup**; it dies with the box.
- `BACKUP_ARCHIVE_PASSWORD` — encrypts the archive. Set it before any copy leaves the box.
- `BACKUP_ALERT_EMAIL` — failure mail. Unset means no mail is sent (the app still boots);
  `backup:monitor`'s exit code is then the only signal.

Configuration is guarded by `tests/Feature/BackupConfigurationTest.php`, which asserts the
things that fail *silently*: that the uploads are actually covered, that the destination is
not nested inside the source, and that archives never land on a servable disk.

### Off-box (still an operator task)

Defense in depth on top of the managed provider's own backups (paid plans only — the Aiven
free tier has none). Nightly to the **Hetzner Storage Box** via `restic` (dedup + encryption
+ easy retention):

```bash
# /usr/local/bin/atriom-backup.sh   (cron: 0 1 * * *)
set -euo pipefail
export RESTIC_REPOSITORY="sftp:uXXXXXX@uXXXXXX.your-storagebox.de:/atriom"
export RESTIC_PASSWORD_FILE=/root/.restic-pass
restic backup /path/to/storage/backups && restic forget --keep-daily 14 --keep-weekly 8 --prune
```

Pointing `restic` at `storage/backups` reuses the archives the scheduler already produces,
rather than running a second `mysqldump` with its own credentials.

- **Tested restore monthly** into a scratch DB — an untested backup is not a backup
  (runbook §5). Nothing in the app can assert this for you.

## 8. App file storage (Hetzner Object Storage, S3)

> **Phase 2. Phase 1 keeps files on the box** (`FILESYSTEM_DISK=local`), which needs no bucket and
> no credentials — every private collection already declares `useDisk('local')`, so uploads, signed
> leases and tenant documents work unchanged.

S3-compatible → Laravel's built-in `s3` driver. **The SDK is NOT installed** — verified against
`vendor/` on 2026-08-29, and it has been a documented-but-unperformed step for as long as this
section has existed. `composer require league/flysystem-aws-s3-v3` **first**: without it a write to
the `s3` disk throws rather than falling back to local, which means it also blocks
`BACKUP_DISKS="backups,s3"` — [STATUS §1.1](../STATUS.md) calls that the highest-consequence
go-live row. Separate bucket per env. `AWS_ENDPOINT` is what makes a non-Amazon provider work; unset,
the SDK builds an `amazonaws.com` hostname and valid credentials fail as though they were wrong.

```
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<hetzner key>
AWS_SECRET_ACCESS_KEY=<hetzner secret>
AWS_DEFAULT_REGION=fsn1                              # or nbg1 / hel1
AWS_BUCKET=atriom-prod                               # atriom-staging on staging
AWS_ENDPOINT=https://fsn1.your-objectstorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```
`php artisan storage:link` still applies for any local public assets; user media/PDF flows use the
`s3` disk directly.

---

## 8.1 PHP opcache — measured, 2026-09-01

Debian's defaults are sized for a small app and this one is not. Measured on the staging box under
real load:

| | Shipped | Set to | Why |
|---|---|---|---|
| `opcache.memory_consumption` | 128 MB | **256 MB** | 103 MB of 128 was in use (**81%**) with 3,287 scripts cached. A full opcache does not fail loudly — it stops caching NEW files, so the symptom is a gradual slowdown nobody can attribute to a cause. |
| `opcache.interned_strings_buffer` | 8 MB | **32 MB** | **Measured at 16.7 MB in use once the ceiling was raised — so the 8 MB buffer was being exhausted.** Filament interns an enormous number of strings across 66 resources. |

Lives in `/etc/php/8.4/fpm/conf.d/99-atriom-opcache.ini`; needs `systemctl reload php8.4-fpm`.

> **Stated honestly: no page-level speedup was demonstrated from this.** The login page measured
> 0.111s before and 0.150s after, which is noise — the per-minute scheduler shares the box and the
> spread across 15 requests was 0.084–0.296s. The change is justified by the two measurements
> above, not by a timing win, and it is a *cliff* being removed rather than a gain being made.
>
> `validate_timestamps` is deliberately left **on** (revalidating every 2s). Turning it off is the
> usual production tuning and would mean `deploy.sh` must also reload FPM, or a release silently
> serves the previous version's code. Not worth the trap on a box that deploys several times a day.

**Where the time actually goes** — the same measurement, and this is the useful half. Per admin
list page, on demo data: SQL is a small fraction and PHP is most of it. The `tenants` list issues
**five aggregate queries per row** — an invoice `exists`, a collectable-balance sum, a credit-note
sum, a payments fetch and a tenant-credit sum — i.e. the four settlement channels computed per row
rather than eager-loaded. `facility-work-orders` is the slowest page and its SQL is only ~119ms, so
it is PHP-bound, not query-bound. Neither is fixed by server tuning.

---

## 9. Server hardening

- **UFW:** default-deny inbound; allow **only** SSH (`22/tcp`) from your admin IP. No 80/443 needed —
  the tunnel is outbound-only.
- **SSH:** key-only (`PasswordAuthentication no`), root login disabled, admin over a sudo user.
- **fail2ban** on sshd · **unattended-upgrades** for security patches.
- Per-env `.env` is `chmod 600`, owned by that env's user; never world-readable.
- `APP_DEBUG=false` in prod (leaks stack traces); `LOG_LEVEL=warning`.
- Rotate/remove `DemoSeeder` accounts before exposing prod (`DEMO_USER_PASSWORD`).

---

## 10. Provisioning order

Two tracks, matching [§2](#2-components-sizing--cost). **Phase 1 is the whole of a first staging
box** — steps 4, 5 and 11 below do not exist yet, which is most of the cost and most of the
credentials.

### Phase 1 — the staging box

1. Create the VPS (Ubuntu 24.04) — check the **renewal** price, not the headline — add SSH key,
   create sudo user, apply [§9](#9-server-hardening). Add ~4 GB of swap
   ([§2](#2-components-sizing--cost)): at 8 GB it is insurance, and an OOM during `npm run build`
   serves both panels unstyled, which reads as a skipped build. **Set the clock to Africa/Cairo** —
   a fresh box comes up on the provider's own zone (Europe/Berlin here), and every log line, cron
   time and `mysqldump` stamp then disagrees with the operator's wall clock.
   *(Note: there is no `ppa:ondrej/nginx` for noble — use Ubuntu's own nginx. Adding it inside a
   `set -e` script aborts the whole run, and with output piped to `tail` it aborts silently.)*
2. `apt` base + `ondrej/php` PPA → PHP 8.4 (+ `-fpm -mysql -redis -mbstring -xml -curl -zip -gd -bcmath -intl -exif`), nginx, redis-server, **mysql-server**, composer, cloudflared.
   **Install them for FPM as well as CLI, and prove it over HTTP.** `composer install` runs under
   `php-cli`; the money columns render under `php-fpm`. A box with `intl` in one and not the other
   installs cleanly, schedules cleanly, passes `atriom:health` from the console — and throws on
   every list, infolist and dashboard showing money. `App\Support\PhpExtensions` names the nine
   extensions and what each costs; `/health` reports the ones missing from the SAPI that answered
   the request, which is the only reading that settles it. `composer check-platform-reqs` is the
   cheap CLI half.
3. Create system user `atriom-staging` + `/var/www/atriom-staging`. Create the local
   `atriom_staging` DB + `stg_u` user — the grant discipline in
   [§3](#the-db-safety-wall-critical) starts now, not when prod arrives, because it is the thing
   that stops a `migrate:fresh` reaching the wrong database later.
4. Set `innodb_buffer_pool_size` ≈ 512 MB so MySQL does not compete with the asset build.
5. Clone → `composer install --no-dev --optimize-autoloader` → write `.env` from
   [STAGING.md §2](STAGING.md) (Redis drivers per [§5](#5-redis--driver-overrides-required) —
   **still mandatory**: health fails a deployed box on the `database` *or* `sync` driver) →
   `key:generate` → `migrate:fresh --seed` → `npm ci && npm run build` → `config/route/view:cache`.
6. FPM pool + nginx vhost on `127.0.0.1:8081` + worker unit + cron
   ([§3](#3-one-box-two-environments-native-separation)); enable + start. **The cron and the worker
   are the step that gets silently forgotten** — around thirty behaviours are inert without them and
   none fails loudly ([STAGING-CUTOVER.md §3](STAGING-CUTOVER.md)).
7. `cloudflared` tunnel + DNS + config ([§4](#4-cloudflare-tunnel-the-only-public-entry)); enable
   service. This is also what makes the default `TRUSTED_PROXIES=*` safe — nginx binds loopback and
   no inbound port is open, so nothing can reach the app directly to forge `X-Forwarded-For`. It
   closes [STATUS §1.6](../STATUS.md) by topology rather than by configuration.
8. Cloudflare: WAF, rate-limits, **Access on the staging hostname**.
9. `php artisan backup:run` then `atriom:backup-verify`, and `atriom:preflight`. Read the expected-red
   rows in [STAGING.md §5](STAGING.md) before treating anything as a blocker.

### Phase 2 — production joins the box

10. **No resize needed** — VPS-1 is already 8 GB. Add system user `atriom-prod`, its FPM pool
    (12 kids; drop staging to 4 so it cannot starve prod), its vhost on `:8080`, worker, cron, and
    the second tunnel ingress.
11. Provision managed MySQL → two DBs + two users ([§6](#6-managed-mysql-aiven-amsterdam)); allowlist
    the VPS IP; download the CA cert — TLS is mandatory. **Install `mysql-client`** now that
    `mysqldump` is no longer on the box.
12. `composer require league/flysystem-aws-s3-v3`, create the object-storage buckets + Storage Box,
    set `FILESYSTEM_DISK=s3` and `BACKUP_DISKS="backups,s3"` ([§8](#8-app-file-storage-hetzner-object-storage-s3)).
13. Give each env its own Redis keyspace (`REDIS_PREFIX`/`REDIS_DB`/`REDIS_CACHE_DB`). Sharing one is
    not merely untidy: the two environments then share `Cache::lock()` names, so staging's billing run
    can hold the lock that stops production double-billing, and neither box shows an error.
14. Work [STATUS §1](../STATUS.md) — backup password, alert email, Sentry DSN, `HEALTH_TOKEN`, 2FA
    roles, demo-account rotation — then the runbook's **pre-flight gates**
    ([PRODUCTION-RUNBOOK.md §7](PRODUCTION-RUNBOOK.md)).

**Each release** thereafter is **`./deploy.sh`** at the repo root, which is
[PRODUCTION-RUNBOOK.md §2](PRODUCTION-RUNBOOK.md) in one command: `git pull` →
`composer install --no-dev` → `npm ci && npm run build` → `migrate --force` → cache rebuild →
`queue:restart` → `atriom:health`. It gates production behind a manual confirm (`--yes` for a CI
caller) and deploys staging freely, as this section always intended. *This file described that
script for months before it existed; every release until 2026-08-13 was somebody retyping the nine
commands, where a skipped `npm run build` renders both panels with no CSS and a skipped
`queue:restart` leaves workers on the previous release's code.*

---

## 11. Scaling & offload path (designed in, used later)

The single box is deliberate for cost + simplicity. When you outgrow it, each step is additive —
nothing here has to be rebuilt:

| Pressure | Move |
|---|---|
| Real data arrives on the box | **Phase 2** — [§2](#2-components-sizing--cost). Not optional and not a growth step: it is what makes the loss of one machine survivable. |
| DB engine load (once off-box) | Resize the managed node up; move off the free tier to get a standby/read-replica for HA. |
| App box CPU/RAM contention | Resize the VPS (vertical); it reboots in minutes. Every provider in [§2.1](#21-why-this-provider-and-what-was-rejected) supports it — but confirm the larger SKU is **in stock** before planning on it, which is what removed Hetzner CX. |
| Staging noise affecting prod | Split **staging** to its own cheap VPS — copy `/var/www/atriom-staging` + its `.env`, add a tunnel ingress rule, done. No data migration, and it need not be the same provider. |
| Prod needs isolation/HA | Move prod to its own box the same way; keep DB shared or split. |
| Want reproducible infra | Lift each env into a Docker Compose stack (the native layout maps 1:1 to services); the compose file becomes the new "copy to a bigger box" unit. |

**Maintainability notes:** keep the two environments byte-identical except `.env`; patch the OS via
`unattended-upgrades`; treat `deploy.sh` + this doc as the source of truth.

**The statelessness is a phase-2 property, not a standing one.** Once MySQL is managed and files are
in object storage, the app box holds nothing that is not in git — so it can be rebuilt from these
steps, or re-provisioned at another provider, and nothing is lost. That is what makes the provider
risk in [§2.1](#21-why-this-provider-and-what-was-rejected) survivable, and it is what turns changing
provider into a re-provision rather than a migration — which stopped being hypothetical on
2026-08-30, when the planned SKU went out of stock before the box was built. **In phase 1 none of it is true**: the database,
the uploads and the archives are all on one disk. Correct for demo data; the reason phase 2 is
gated on real data rather than on load.

---

## Env deltas vs the runbook

[PRODUCTION-RUNBOOK.md](PRODUCTION-RUNBOOK.md) describes the generic single-box/local-MySQL baseline.
On **this** topology, override:

| Runbook default | This infra | Why |
|---|---|---|
| `SESSION_DRIVER=database` | `redis` | MySQL is off-box; keep sessions off the network hop. |
| `QUEUE_CONNECTION=database` | `redis` | Same — worker polling must not hit the remote DB. |
| `CACHE_STORE=database` | `redis` | Same. |
| single `/var/www/atriom` | `/var/www/atriom-{prod,staging}` | Two co-located environments. |
| supervisor worker | systemd unit per env (either is fine) | Native, per-env. |
| open 80/443 + TLS on nginx | Cloudflare Tunnel, loopback-only nginx | Zero exposed ports; origin IP hidden. |

Everything else in the runbook (deploy steps, worker/scheduler requirement, observability,
pre-flight gates, rollback) applies unchanged — per environment.
