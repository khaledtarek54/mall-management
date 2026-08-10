# 36 — Marketing posts (the shopper feed)

> Offers, events and mall news shown to **shoppers** in the visitor app — composed by the operator
> or submitted by the retailer and reviewed before anyone sees them. The first unauthenticated
> read surface in the system.
>
> Benchmarks: Mallcomm's *Offers & Discounts* module, Placewise's shopper-app CMS, Mall Maverick's
> promo-approval workflow. Field vocabulary from schema.org `Offer` and Google Merchant promotions.

## 1. Purpose & business context

Atriom could tell **tenants** things ([module 27](27-announcements.md)) and could account for what
marketing **spent** ([module 13](13-marketing.md)). It had no way to tell a **shopper** anything,
and no concept of a visitor at all: every one of the 38 `/api/v1` routes authenticated a `Tenant`.

A mall's app is the other half of the marketing fund. The levy is collected on rent, the spend is
posted to the GL — and what the money actually *bought* ("Defacto, 20% off until Thursday") lived
in a WhatsApp group and a designer's Dropbox. This module is the content side, and it deliberately
links back to the money side (§7).

Two audiences, one table:

- **Shoppers** — the visitor app, unauthenticated, reading a per-mall feed and a store directory.
- **Retailers** — other tenants, who both *see* the feed and *submit to it*.

## 2. Domain model

| Table/Model | Key columns | Meaning |
|---|---|---|
| `marketing_posts` / `MarketingPost` | `asset_id` (the mall) · `tenant_id` (nullable — the store, null = mall-wide) · `type` · `status` · `audience` · `title`/`_ar`, `summary`/`_ar`, `body`/`_ar`, `terms`/`_ar`, `discount_label`/`_ar` · `starts_at`/`ends_at` · `display_from`/`display_until` · `is_featured`, `priority` · `cta_label`/`_ar`, `cta_url` · `created_by`, `submitted_by_tenant_user_id`, `reviewed_by`, `reviewed_at`, `review_notes`, `published_at` · `view_count`, `click_count` · `search_text` | One card in the feed. Soft-deletes. |
| `tenants` (extended) | `trade_name`/`_ar` · `retail_category` · `public_description`/`_ac` · `website_url` · `instagram_handle` · `is_listed` · `logo` media collection | The **store directory** — who a retailer is to a shopper. |
| `marketing_spends` (extended) | `marketing_post_id` (nullable) | The campaign a spend line paid for (§7). |

`type` — `offer` · `event` · `news`. `status` — `draft` · `pending` · `published` · `rejected` ·
`archived`. `audience` — `visitors` · `tenants` · `both`. All strings, never DB enums (house rule).

**Property isolation:** `MarketingPost` is **OWNED** with a direct `asset_id`. A shopper reading a
card is standing in one building; there is no portfolio-wide offer.

### The two date pairs

The single most important shape decision, and the one a reader is most likely to "simplify":

| Pair | Question it answers | Shown to the shopper? |
|---|---|---|
| `starts_at` / `ends_at` | **Validity** — when the discount is honoured. schema.org `validFrom`/`validThrough`. | Yes — "valid until 31 Aug". This is the promise. |
| `display_from` / `display_until` | **Visibility** — when the card is in the app at all. | **No.** Scheduling is internal. |

They are genuinely different. A Black Friday offer is teased for a week and valid for a day; a
Ramadan campaign is published the moment artwork clears review and valid only from the 1st.
Collapsing them into one pair forces the operator to lie about one of the two, and the lie lands on
the shopper, who is told an offer is over before it started. Every mature promotions system makes
the same split.

`display_*` is nullable and **falls back to the validity window** — `COALESCE(display_from,
starts_at)`. Both null on a published post means always-on, which is what an operator means by a
mall-news item with no dates.

## 3. Business rules & invariants

### The ONE visibility predicate: `MarketingPost::liveFor()`

Five surfaces ask "is this on screen right now" — the public feed, the public detail screen, the
click endpoint, the tenant feed, and the admin *Showing now* filter/tab. **All five call
`liveFor()`; none re-derives it.** Two surfaces that disagree about whether an offer is live is not
a failure any test at either end would catch, and the symptom (a shopper sees an expired discount
the operator's screen says is gone) reads as a data problem rather than a code one.

```
status = published
AND (COALESCE(display_from, starts_at) IS NULL OR COALESCE(display_from, starts_at) <= now)
AND (COALESCE(display_until, ends_at) IS NULL OR COALESCE(display_until, ends_at) >= now)
AND audience IN (<asker>, 'both')          -- audience filter skipped entirely when null
AND (tenant_id IS NULL OR <the store is still showable>)
```

**The store clause** (added in the pre-merge review): a store-attributed post is live only while
its store is `is_listed`, `active`, and still holding an active lease **in this post's property**
(via the `lease_unit` pivot). It closes two failures that both shipped green:

- a retailer's lease ends, they move out, and their approved offer keeps advertising a shop that
  is not there until its end date catches up;
- an unlisted retailer — one the operator deliberately hid from the directory — still had their
  name and logo broadcast on every card, while the tap-through to their store page **404'd**,
  because the store endpoint checked `is_listed` and the feed did not.

It lives in the predicate rather than in the public controllers so there is still exactly one
answer. Putting it on the shopper surface alone would make the operator's *Showing now* disagree
with what shoppers see — the drift the predicate exists to prevent.

The audience argument is the asymmetry the column exists for: a shopper asks as `visitors` and
never sees a staff discount; a **retailer asks with no filter** and sees everything running in
their mall, because they are both a member of the public and a member of the community.

Feed ORDER is likewise one method — `feedOrder()`: featured, then priority, then newest. The
carousel and the list are the same query, so a client renders the leading `is_featured` run as its
carousel. A separate `/carousel` endpoint would eventually disagree with the list about what is top.

### Nothing a retailer can call reaches the public

`SubmitMarketingPostService` ends at `pending`, full stop. On every tenant surface, `status`,
`is_featured`, `priority` and the display window are **never read from input** — the API action
strips them and the portal form does not render them. A retailer may edit only a `draft` or a
returned (`rejected`) post; once submitted or published it is read-only to them, because otherwise
they could swap approved artwork for something nobody reviewed and approval would mean nothing.

### A retailer can only post into a mall they trade in

`assertTenantTradesIn()` is the property-isolation **write guard** for the non-Filament surfaces.
Reached via `activeLeases.units` — the `lease_unit` pivot, **not** `leases.unit_id`, which is only
the master unit and would wrongly refuse a retailer whose presence in this mall is an *additional*
unit on a multi-unit lease. (Same trap the announcement fan-out documents.)

### Rejection requires a reason

`RejectMarketingPostService` refuses an empty one. A retailer told only "rejected" resubmits the
same artwork, the operator rejects it again, and the queue becomes a loop both sides blame on the
other. The reason is carried back on the portal row, the API resource and the push notification.

### Publication checks live in one place

`PublishMarketingPostService` is the only path to `published`; `ApproveMarketingPostService`
delegates to it. Refusals (all `DomainException` → a toast, never a 500):

- no title;
- `ends_at` before `starts_at`, or `display_until` before `display_from`;
- the window has **already closed** — otherwise the operator sees "Published ✓" and the hourly
  sweep files it away minutes later with nothing saying why;
- **no hero image**, for anything not `tenants`-only. A feed row with no artwork renders as a grey
  box in every mall app there is.

### Archive, don't delete

`archived` keeps the campaign and its engagement counters, which is what makes "which of last
Ramadan's offers worked" answerable a year later. `DeletionPolicy` classifies the model `ALLOWED`
anyway (with a stated reason): a post typed into the wrong mall is genuinely a row that should not
exist, and refusing it would leave the register full of things the team has to mentally skip. The
UI offers Archive far more prominently than Delete.

## 4. Lifecycle

```
              ┌──────── operator composes ────────┐
              ▼                                   │
  draft ──submit──▶ pending ──approve──▶ published ──archive──▶ archived
    ▲   ◀withdraw──    │                      │                    ▲
    │                  │                      └─ marketing:expire-posts (window closed)
    └──── reject (with a reason) ──▶ rejected ─┘ (tenant edits + resubmits)
```

Operator-composed posts skip `pending` — the operator *is* the approver. Retailer-composed posts
(`created_by` null) cannot.

## 5. Services, jobs & commands

| Class | Action |
|---|---|
| `PublishMarketingPostService` | **The only path to `published`.** Locks the row, runs the coherence checks, stamps `published_at`/reviewer, clears a stale rejection note. Idempotent. |
| `ApproveMarketingPostService` | Queue verdict → delegates to publish → notifies the retailer (best-effort, isolated). |
| `RejectMarketingPostService` | Queue verdict → `rejected` with a mandatory reason → notifies. |
| `SubmitMarketingPostService` | Retailer → `pending`; `assertTenantTradesIn()`; `withdraw()` back to draft. |
| `ArchiveMarketingPostService` | The retirement path. Refuses a `pending` post (it needs a verdict, not a filing). |
| `marketing:expire-posts` | **Hourly.** Archives published posts past their display window. Idempotent + lock-safe. `--dry-run`. |
| `MarketingPostReviewedNotification` | Bell + push (never email), carrying the verdict **and the reason**. |

## 6. Surfaces

### `/admin` — `MarketingPostResource` (nav group *Marketing*)

Register + review queue on one screen (tabs: All · Awaiting review · Showing now · Drafts &
returned · Archived), with a **nav badge** on the pending count — the one state with something like
an SLA. Actions: Approve · Return (with reason) · Publish · Archive, each gated **twice**
(`visible()` + `authorize()`) on a predicate named once.

Permissions: `marketing_posts.{view,create,edit,approve,delete}`. **`approve` is separate from
`edit`** — deciding what the mall says to the public is a different authority from tidying a draft.
Granted to `marketing` (all but delete) and, via the blanket non-delete grant, `manager`/`mall_admin`;
`viewer`/`owner` get `.view`. Delete is super_admin-only; bulk-delete off.

`is_featured` and `priority` are **disabled rather than hidden** without `approve` — an editor can
see the field exists and why its value is what it is.

### `/portal` — `Portal\MarketingPostResource`

The retailer's own list. Compose → Send for review → Withdraw. Tenant-admin only for writes
(the portal's `is_admin` rule). The mall's rejection reason is a **column**, not a detail screen.

### `/api/v1/me/*` (Sanctum, `Tenant`)

`GET|POST me/marketing-posts` · `GET|POST me/marketing-posts/{id}` (POST for the update — a
multipart body carrying the hero image does not survive PHP's PATCH handling) ·
`POST …/{id}/submit` · `POST …/{id}/withdraw` · `DELETE …/{id}` · `GET me/feed`.

Cross-tenant ids return **404**, never 403 — the no-enumeration rule.

### `/api/v1/public/*` — UNAUTHENTICATED

`GET malls` · `GET malls/{code}/posts` · `GET malls/{code}/posts/{id}` ·
`POST malls/{code}/posts/{id}/click` · `GET malls/{code}/stores` · `GET malls/{code}/stores/{id}`

Three things keep it safe, and none of them is "we remembered to filter":

1. **`EnsureMarketingPostsEnabled`** 404s the whole surface when the module is off. A permission
   gate cannot help here — there is no user to hold a permission.
2. **Hand-written field allowlists** (`PublicMarketingPostResource`, `PublicStoreResource`), not
   model serialization and not conditional branches inside a shared resource. Nothing from
   `tenants` reaches a stranger unless someone typed it out. Absent by construction: workflow
   state, `review_notes`, engagement counters, the display schedule, and every identity column on
   the retailer (tax card, commercial register, national ID, phone, email, legal name).
3. **`liveFor()`**, shared with every other surface.

404 for everything not servable — unknown/inactive mall, `ALL` pseudo-asset, post outside its
window, a post id borrowed from another mall, a store not trading here. Throttled 120/min for
reads; the click write gets its own 30/min bucket.

## 7. Integrations

- **Marketing spend ([module 13](13-marketing.md)):** `marketing_spends.marketing_post_id` links
  cost to campaign, set from a **Campaign** Select on the spend form (scoped to the budget's own
  property). Placewise and Mallcomm hold the content; Yardi holds the ledger; reconciling them is
  normally a spreadsheet. Many spends → one post (artwork, printing, influencer). Optional by
  design — requiring it would push operators into inventing a campaign row to record a cost.
- **Media:** `hero` (single, 16:9) + `gallery` on the **public** disk, and `tenants.logo` likewise —
  the only public collections besides property branding, because the reader is deliberately
  unauthenticated. Registered in `MediaPrivacyConformanceTest::PUBLIC_COLLECTIONS` with the reason.
  `tenants.documents` stays private; separate collections are exactly what lets the brand mark be
  public without the paperwork following it.
- **Feed cache:** `App\Support\MarketingFeedCache` — a per-property version token in the cache key,
  plus a 60s TTL. Version-only would break the day someone adds a seventh way a post changes;
  TTL-only means an operator approves an offer, opens the app, sees nothing, and approves it again.
  Both, so correctness never depends on remembering. Bumped by:
  - `MarketingPost` `saved`/`deleted`/`restored` — any change to a card;
  - `Tenant` `saved`, when a **directory** field changed (`trade_name*`, `retail_category`,
    `public_description*`, `website_url`, `instagram_handle`, `is_listed`, `status`), fanned out to
    every mall that retailer trades in. Narrowed to those fields on purpose: a tenant row is saved
    constantly by leasing and billing work, and bumping on every save makes the cache pointless.

  **Two bounded lags, both ≤ the TTL and both deliberate.** Replacing a store *logo* is a
  medialibrary write, not a `Tenant` save; and a *lease ending* (which drops a post via the store
  clause) does not touch this cache either. Sixty seconds is the whole exposure, and hooking every
  lease transition and media event into a shopper cache is a lot of coupling to buy a minute.

  The shopper view/click counters are **builder increments**, so a read neither invalidates the
  cache it just populated nor re-folds the search blob.
- **Feature flag:** `Modules::KEYS['marketing_posts']`, on by default. A mall with no visitor app
  should not be asked to review offers nobody will read.

## 8. Extension points

- **New content type** (e.g. `job`, `service`): add to `MarketingPost::TYPES` + the two lang blocks.
  Nothing else branches on type.
- **Push a published offer to the mall's tenants:** reuse `MarketingPostReviewedNotification`'s
  shape with a new notification fired from `PublishMarketingPostService`.
- **Per-shopper personalisation / loyalty** (the Placewise direction): needs a shopper identity,
  which this module deliberately does not introduce. The feed is currently identical for everyone,
  which is what makes it cacheable.
- **Real analytics:** replace the counters with an events table. See the honesty note in §9 first.
- **Localised feed:** both languages ship on every payload and the client picks. Server-side
  resolution would need `Accept-Language` in the cache key — cheap, but do it deliberately.

## 9. Gotchas

1. **`display_*` vs `starts_at`/`ends_at` is not redundancy.** See §2. The sweep, the predicate and
   the shopper card each read a *different* one of the pair, on purpose.
2. **Engagement counters are indicative, not audited.** An unauthenticated endpoint can be called
   in a loop; throttling changes how long that takes, not whether it works. Useful for comparing two
   campaigns run under the same conditions; **not** billable impressions. Stated here rather than
   implied because someone will eventually put them in front of an owner.
3. **`is_listed` is a display switch, not a security boundary.** What keeps a retailer's paperwork
   off the internet is the allowlist in `PublicStoreResource`. It *does* now also drop that
   retailer's cards from the feed (see the store clause in §3) — that is about consistency with
   the directory, not confidentiality.
4. **`Unit::leases()` is master-units-only.** It is a `hasMany` on the denormalized
   `leases.unit_id`; the pivot relation is **`allLeases`**. The portal's property Select used the
   wrong one and silently omitted any mall the retailer occupies only as an *additional* unit —
   a mall they could post to from the mobile API but not from the portal. Pinned by an explicit
   A/B in `MarketingPostReviewFindingsTest`.
5. **Store locations are scoped to the mall being browsed.** Returning a retailer's whole footprint
   would let anyone map a chain across the operator's portfolio from a public URL.
6. **`isTenantAuthored()` keys on `created_by` being null**, not on
   `submitted_by_tenant_user_id` being set — the portal knows which `TenantUser` is acting, the
   mobile API authenticates the `Tenant` itself and has no user to record. A predicate reading the
   portal-only column would classify every API submission as operator-authored and silently skip
   its notification.
7. **`ImageColumn` has no `collection()`.** Media-backed table columns need
   `SpatieMediaLibraryImageColumn`; the wrong one fatals *on render*, so only a table test **with
   rows** catches it. (It did.)
8. **`Public` is a PHP reserved word** and cannot be a namespace segment — hence `PublicFeed`.
9. **A post whose tenant is deleted goes mall-wide rather than disappearing** (`nullOnDelete`).
   Visible and repairable beats silently erasing published content.

## 10. Tests

- `tests/Feature/MarketingPosts/MarketingPostLifecycleTest.php` — the state machine: publish
  idempotency, every refusal (each paired with an authorised control), the tenant-submission
  guards including the `lease_unit` pivot trap, rejection reasons, archive.
- `tests/Feature/MarketingPosts/PublicFeedApiTest.php` — the unauthenticated surface: visibility
  (status, both windows, audience, cross-mall, `ALL`, module off) and **leakage** — every
  absence assertion paired with a positive control so it cannot pass on an empty response.
- `tests/Feature/MarketingPosts/MarketingPostAdminTest.php` — table renders **with rows**, tabs,
  nav badge, RBAC (the `canApprove()` predicate asserted directly — neither `callAction()` nor
  `mountAction` can distinguish a gate from its absence here), property isolation, and the
  queue→feed round trip through the real public endpoint.
- `tests/Feature/MarketingPosts/MarketingPostPortalTest.php` — retailer scoping, the edit window,
  `is_admin` writes, module-off.
- `tests/Feature/MarketingPosts/MarketingPostExpirySweepTest.php` — the sweep (including the
  display-window precedence and idempotency) and the feed cache's invalidation behaviour.
- `tests/Feature/Regression/MarketingPostReviewFindingsTest.php` — the six defects found in the
  pre-merge review, each of which had shipped green: the departed/unlisted store, the portal's
  master-unit-only property list (as an explicit A/B against the pivot query), the module flag on
  the retailer API, directory-cache invalidation (and its counterpart — that an ordinary billing
  edit does *not* bust the cache), and the non-fillable counters.
- Gates: `PropertyIsolationConformanceTest` (registered in the must-guard set),
  `MediaPrivacyConformanceTest`, `DeletionPolicyConformanceTest`, `SearchPolicyConformanceTest`,
  `AdminSmokeManifestConformanceTest`, `ActionAuthzConformanceTest`.

---

**Document version:** 2026-08-10 | Laravel 13 + Filament 4
