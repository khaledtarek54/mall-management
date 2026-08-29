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
        │  Hetzner Cloud VPS  (Ubuntu 24.04 · 8GB/4vCPU · DE)│
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

### Phase 1 — staging only (~€4/mo)

| Component | Spec | ~Monthly |
|---|---|---|
| Hetzner Cloud VPS | **CX22** — 2 vCPU / 4 GB / 40 GB, Falkenstein or Nuremberg | **€3.79** |
| MySQL 8.4 | **on the box** | €0 |
| Redis | on the box — not optional, see [§5](#5-redis--driver-overrides-required) | €0 |
| Uploads + backup archives | the box's own disk | €0 |
| Cloudflare | Free plan + Tunnel + Access on the staging hostname | €0 |
| Domain | at Cloudflare | ~$10 / yr |
| **All-in** | | **~€3.79/mo** |

**4 GB needs 4 GB of swap, and that is a requirement rather than a tuning note.** `npm run build`
compiles the app assets *and* the VitePress handbook, and that peak alongside MySQL, Redis and FPM
is what OOM-kills a 4 GB box mid-deploy — leaving both panels serving unstyled HTML, which is the
same symptom as a skipped build and is diagnosed as one. Set `innodb_buffer_pool_size` to ~512 MB
while you are there; the default is small enough that MySQL will not fight the build, but the
default is not what an unattended `apt` install always leaves you.

### Phase 2 — production joins the same box (~€30–40/mo)

The box becomes two environments per [§3](#3-one-box-two-environments-native-separation). Resize
CX22 → **CX32** (4 vCPU / 8 GB, **€6.80**) — a reboot, no migration; RAM and CPU resize both ways,
disk only grows. Then move off the box the three things whose loss is now unacceptable:

| Add | ~Monthly | What it buys |
|---|---|---|
| Managed MySQL — Aiven paid node, or DO Managed MySQL FRA1 | **~$15–25** | A standby + PITR, and the two-user grant wall in [§3](#the-db-safety-wall-critical) that makes a misfired `migrate:fresh` unable to reach production |
| Object storage, bucket per env | **~€6** | `FILESYSTEM_DISK=s3` — uploads survive the box |
| Off-box backup target (Storage Box + restic) | **~€4** | `BACKUP_DISKS="backups,s3"`; a second failure domain |

**Do not defer this past the day real data lands.** In phase 1 the database, the uploads and the
archives are all on one disk, so anything that takes the box — hardware, or the account problems in
[§2.1](#21-provider-risk-hetzner-specifically) — is total loss. That is correct for demo data and
indefensible for a tenant's signed lease.

### Phase 3 — split, when something actually pushes

See [§11](#11-scaling--offload-path-designed-in-used-later). Nothing here is scheduled; each row is
a response to a measured pressure, not a milestone.

### 2.1 Provider risk (Hetzner specifically)

The hardware and network are not the weak point — Hetzner has run its own data centres since 1997,
and the price is 3–5× under DigitalOcean or Vultr for the same specs, which is why this box is
worth the caveats. **The risk is commercial, and two of the three land specifically on an
Egypt-based buyer.** Each has a cheap mitigation; take all three.

| Risk | Mitigation |
|---|---|
| **Suspension on an unpaid invoice.** Reported to happen with little warning. An Egyptian company paying a German provider by card meets international declines, FX limits and bank blocks routinely — and the consequence here is a stopped box, not a dunning email | **Prepay the account and keep a balance.** Second card on file. Put a *monitored ops mailbox* on the account, never a personal address |
| **Capacity restrictions.** Since June 2026 Hetzner has limited new cloud-server creation for new customers and, at random, some existing ones | **Provision now**, while it is only staging. Having the box in the account before production day is worth more than the €4 |
| **Object Storage degradation** — a hel1 incident took ~31 days to fully mitigate in H1 2026 | Never let it hold the **only** off-box backup copy. Put that copy on the Storage Box (a different system) or another vendor — Backblaze B2, Cloudflare R2. This is [§7](#7-backups)'s own failure-domain rule, applied to the provider |

**Contabo was evaluated and rejected — it is not cheaper.** Cloud VPS S (4 vCPU / 8 GB) is ~$11.31
against CX32's €6.80 for the same CPU and RAM; its only advantage is disk size, which is not this
system's constraint. What it costs is the thing this workload is most sensitive to: a Filament list
page issues 130–400 queries ([§11](#11-scaling--offload-path-designed-in-used-later) measures it),
and Contabo's documented high disk latency and inconsistent CPU do not make pages uniformly slower,
they make them *randomly* slow — which is the complaint nobody can reproduce. It is a real company
(Munich, 2003); it is the wrong fit here, and doubly so in phase 1 with MySQL on the same disk.

**If a payment-related suspension is unacceptable to the operator**, the answer is DigitalOcean
Frankfurt — roughly 3× the all-in cost, and it deletes the Aiven vendor entirely by putting managed
MySQL in the same console. That is a defensible purchase, not a downgrade of this plan.

**Not yet answered: data residency.** This system holds Egyptian tenants' tax cards, national IDs,
signed leases and employee payroll, and Egypt's PDPL has cross-border transfer rules. Hosting in
Germany may be fine or may need consent or licensing — that is a question for the operator's
counsel, and it is far cheaper to ask before the box exists than after real data is on it.

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
`/api/v1` login routes · "Under Attack" mode toggle. **Put staging behind Cloudflare Access**
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
> **different vendor or a different system** from the app's object storage — [§2.1](#21-provider-risk-hetzner-specifically)
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

1. Create Hetzner **CX22** (Ubuntu 24.04, Falkenstein/Nuremberg), add SSH key, create sudo user,
   apply [§9](#9-server-hardening). **Add 4 GB of swap** — see [§2](#2-components-sizing--cost);
   without it `npm run build` OOMs mid-deploy and the panels serve unstyled HTML.
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

10. Resize CX22 → CX32 (reboot). Add system user `atriom-prod`, its FPM pool (12 kids; drop staging
    to 4 so it cannot starve prod), its vhost on `:8080`, worker, cron, and the second tunnel ingress.
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
| App box CPU/RAM contention | Resize the Hetzner VPS (vertical); it reboots in minutes. |
| Staging noise affecting prod | Split **staging** to its own cheap Hetzner VPS — copy `/var/www/atriom-staging` + its `.env`, add a tunnel ingress rule, done. Same-provider, no data migration. |
| Prod needs isolation/HA | Move prod to its own box the same way; keep DB shared or split. |
| Want reproducible infra | Lift each env into a Docker Compose stack (the native layout maps 1:1 to services); the compose file becomes the new "copy to a bigger box" unit. |

**Maintainability notes:** keep the two environments byte-identical except `.env`; patch the OS via
`unattended-upgrades`; treat `deploy.sh` + this doc as the source of truth.

**The statelessness is a phase-2 property, not a standing one.** Once MySQL is managed and files are
in object storage, the app box holds nothing that is not in git — so it can be rebuilt from these
steps, or re-provisioned at another provider, and nothing is lost. That is what makes the provider
risk in [§2.1](#21-provider-risk-hetzner-specifically) survivable, and it is what turns leaving
Hetzner into a re-provision rather than a migration. **In phase 1 none of it is true**: the database,
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
