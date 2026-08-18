# Module 36 — Marketing Posts (the shopper feed) · gap analysis

> **Round 3, 2026-08-18.** First audit — on the never-gap-analysed list in
> [PROJECT-MAP](../PROJECT-MAP.md). Method: [000-plan.md](000-plan.md).
>
> **Audited as a security surface first.** This is the **only unauthenticated read surface in the
> system**, so the questions asked here are what an anonymous caller can see, cause and enumerate —
> not feature parity. No competitor benchmark covers a mall shopper feed; none is claimed.

## 1. Verdict

**The confidentiality boundary is sound.** I went looking for the four ways this normally leaks —
unpublished content, PII, cross-property data, and an existence oracle — and found none. One
**scope gap** remains, recorded rather than fixed because closing it costs a migration and is a
business decision: the operator's portfolio is publicly enumerable.

## 2. Finding

### 🟡 F-A — every managed property is publicly listed, with no way to withhold one

`GET /api/v1/public/malls` returns **every** `Asset` with `is_active = true` — code, name, city and
logo — and `resolveMall()` accepts any of them. There is no `is_publicly_listed` column on `assets`
(verified against the live schema, not just the model), so the only way to keep a property off the
shopper app is to deactivate it, which would break every operational screen that depends on it.

**Why it is worth stating.** Eltizam runs malls *on behalf of* owners. The list of properties it
manages is commercial information about the operator, and publishing it is a decision — currently
made implicitly by `is_active`, a flag that exists for an unrelated reason. A mall still in fit-out,
one whose owner has not agreed to a public presence, or one whose management contract is being
renegotiated all appear the moment they are active.

The asymmetry is what makes it look unconsidered rather than chosen: a **store** can be withheld
from the directory (`tenants.is_listed`), and [module 36 §9.5](../modules/36-marketing-posts.md)
deliberately scopes store locations to the mall being browsed so *"anyone [cannot] map a chain
across the operator's portfolio from a public URL"* — the same instinct, applied one level down.
`/public/malls` maps the operator's own portfolio.

**Not fixed**, for the same reason as [module 35's scope gap](35-rentable-items.md): it needs a
column, a migration, a form field and a default. The default is the decision — ship it defaulting to
listed and nothing changes for anyone; default to unlisted and the feed empties until each property
is opted in. That is the operator's call, not mine.

## 3. Verified clean

| Hypothesis | Result |
|---|---|
| An unpublished or scheduled post reaches a shopper | **False** — visibility is `MarketingPost::liveFor('visitors')` and nothing else: `status = published`, plus the display window. Both public controllers and the click endpoint use that one predicate, so the operator's "Showing now" cannot drift from what shoppers see |
| The feed filters on the wrong date pair | **False** — `COALESCE(display_from, starts_at)` / `COALESCE(display_until, ends_at)`. The DISPLAY window governs visibility and falls back to validity; `starts_at`/`ends_at` are exposed to the shopper as the offer's validity, which is what a shopper should read |
| A retailer's PII reaches the internet | **False** — the public resources expose an allowlist (title/body/discount/terms, hero + gallery, store name, logo, category, instagram, website, locations). No phone, email, tax ID, commercial register or contact. No public resource reaches a `tenant->` attribute directly |
| A post keeps advertising a shop that has left | **False** — a store-attributed post is live only while its store is listed, active, and holding an active lease **in that post's property**, correlated to the post's own asset |
| A retailer's footprint across the portfolio is exposed | **False** — store locations are scoped to the mall being browsed, deliberately (§9.5) |
| The click endpoint is an existence oracle for drafts | **False** — it re-applies the same `liveFor` predicate and 404s otherwise, so a stale or unpublished id cannot be probed *or* used to inflate a retired campaign |
| Turning the module off leaves the public routes live | **False** — `EnsureMarketingPostsEnabled` fronts every public route group, so the surface 404s rather than merely disappearing from the nav |
| The `ALL_PROPERTIES` pseudo-asset is reachable publicly | **False** — refused explicitly in `resolveMall()` |

## 4. Noted, not defects

- **Engagement counters are inflatable**, and the module doc says so plainly rather than implying
  otherwise. An unauthenticated endpoint can be looped; the 30/min bucket makes it cost something.
  The honesty is the right call — the risk is someone later showing these to an owner as impressions.
- **No cache-poisoning vector**: the feed cache key carries a per-property version that publishing
  and archiving bump, and every component of the key is either server-derived or validated against
  a fixed list (`type` against `MarketingPost::TYPES`, `featured` cast to bool, page/per-page ints).
