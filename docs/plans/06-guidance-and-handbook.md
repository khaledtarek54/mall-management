# 06 · In-app guidance + the bilingual interactive handbook

> Every screen explains itself in Arabic and English; field help stops being a wall of text; and the
> visual handbook grows into a deployed, bilingual, interactive reference for all 36 modules whose
> data is **generated from the registries** rather than typed.

**Status:** **ALL SIX PHASES SHIPPED** — 2026-08-12.

---

## 1. Why

Three gaps in how Atriom explains itself, each measured against the code rather than assumed.

### 1.1 In-app guides cover 13 of 81 screens

`App\Support\ResourceGuides::GUIDES` registered 13 admin resources. The panel actually carries:

| Surface | Count | Have a guide |
| --- | ---: | ---: |
| Admin resources | 49 | 13 |
| Admin pages (`app/Filament/Admin/Pages`) | 25 | 0 |
| Portal resources | 7 | 0 |
| **Total** | **81** | **13** |

*(83 after the two notification-centre pages landed mid-build.)*

`ResourceGuideConformanceTest` states the omission deliberately — *"coverage across all ~45 resources
is still deliberately not asserted — a registry padded with exemption reasons would be noise."* That
call is now reversed: with content written for every screen, the registry is no longer padding, and
an unclassified screen should fail the build the way an unclassified model does in `DeletionPolicy`.

### 1.2 Field help is a wall of text — the problem is length, not placement

`lang/en/admin.php` carries **125 `helpers.*` strings**:

| Length | Count |
| --- | ---: |
| over 12 words | 100 |
| over 20 words | 60 |
| longest | 79 words |

`LeaseForm` renders **29 permanent hint lines**, several of them full paragraphs, which roughly
doubles the form's height and trains the operator to skim past all of them. Meanwhile the app
contains **exactly one `hintIcon`** (`ChargeCodeForm.php:64`) and it passes **no tooltip** — so it
renders an icon that says nothing. The affordance that solves this has never been used.

The fix is not "tooltips instead of helper text". Hiding a constraint behind hover is worse than
showing it: it disappears on touch, and a first-time operator can't see the rule that would have
stopped them entering the wrong thing. The fix is to sort each string by **what it does**.

### 1.3 The handbook explains concepts, not modules — and isn't deployed

`docs/visual/` is a VitePress site: 25 pages, a custom Atriom theme (216 lines of CSS), local search,
pictures-first. It draws money flows and record lifecycles well. It has **no per-module reference,
no interactivity, no Arabic, and no deployment** — it exists only behind `npm run docs:dev`.

---

## 2. Decisions

| Question | Decision | Why |
| --- | --- | --- |
| Field help pattern | **Three homes** — see §4 | Always-visible for what changes your input; tooltip for the "why"; guide panel for what's really module-level |
| Docs stack | **Keep VitePress** | Already in the repo with the Atriom theme; Vue-in-markdown is the strongest interactivity story of the candidates; a second stack is a second thing to maintain |
| Handbook language | **Full EN + AR, RTL** | Matches the rest of the system — the operator's staff read Arabic |
| Handbook hosting | **`/handbook` behind admin login** | It documents posting rules, GL mappings and internal controls; that isn't world-readable material |

Stacks considered and rejected: **Astro Starlight** (better stock i18n, but means porting 25 pages
and the theme, and running a second JS toolchain beside the app's Vite build); **Docusaurus**
(heaviest, React, build time grows with page count); **Mintlify** (best-looking, zero pipeline, but
an external SaaS — the handbook describes Eltizam's internal accounting).

---

## 3. Phase A — a guide on every screen (EN + AR)

### 3.1 Registry

`ResourceGuides` became `App\Support\ScreenGuides` — the concept changed from *resources* to *screens*,
and it was a three-call-site rename. `GUIDES` (now `SCREENS`) was already keyed by class-string
and `GuideAction::for()` touches no resource-specific API, so `Filament\Pages\Page` subclasses work
unchanged. Two additions:

- **`ScreenGuides::EXEMPT`** — `class => reason`, the escape hatch for a screen where a guide would
  be noise. **It shipped empty.** The two obvious candidates — the login form and Filament's tenancy
  registration screen — extend `Filament\Pages\SimplePage` rather than `Filament\Pages\Page`, so
  discovery never offers them and exempting them would have been a registry entry that classifies
  nothing. The gate rejects an EXEMPT entry that is not a discovered screen, so it cannot fill up
  with that mistake.
- **A `portal.` key namespace** in `guides.php` — the portal's reader is the retailer, not the
  operator, so the same module needs different words there.

### 3.2 Screens written (70)

**Admin resources (36 new):** accounting periods · announcements · approval rules · areas · bank
accounts · bank statements · custodies · departments · deposit transactions · disbursements ·
employees · equipment · expenses · fixed assets · inventory items · journal entries · ledger accounts
· maintenance plans · work orders · marketing budgets · marketing posts · owner requests · owner
statement runs · payrolls · post-dated cheques · purchase requests · roles · SLA policies · stock
movements · tenant requests · users · utility meters · vendor bills · vendors · violations ·
warehouses

**Admin pages (25):** activity log · AR aging · AR aging by type · AR collections · balance sheet ·
billing run preview · cash flow · configuration health · dashboard · expiration schedule · general
ledger · income statement · month-end close · occupancy cost · occupancy map · property overrides ·
rent roll · report hub · reports · sales analytics · settings · trial balance · VAT return · weekly
spend · workflows

**Portal resources (7):** invoices · payments · leases · requests · sales declarations · CAM
allocations · marketing posts

**Notification centres (2):** the admin and portal alert-history pages, which landed from another
session mid-build. The gate caught both as unclassified the first time it ran — which is the
behaviour that was wanted from it.

Each carries the four fields the existing guides use, and they are the four questions actually asked:

- `purpose` — what this screen is, in one sentence
- `steps` — how the everyday task is done
- `affects` — **what changes elsewhere**, the one thing nothing else in the app tells you
- `rules` — the constraints that would otherwise surprise someone

Written in module groups (leasing → billing/AR → CAM/sales → operations → procurement/inventory →
HR/treasury → accounting/GL → config/master data → portal), because those groups are also the
Phase E page groups: the same facts get established once and reused.

### 3.3 Mounting

One line per page in `getHeaderActions()`, matching
[ListLeases.php:26](../../app/Filament/Admin/Resources/Leases/Pages/ListLeases.php). All 25 admin
pages already define `getHeaderActions()`; 5 of 7 portal list pages do too.

### 3.4 Gate

`tests/Feature/Scenarios/ScreenGuideConformanceTest.php` (replacing `ResourceGuideConformanceTest`) asserts:

1. every admin resource / admin page / portal resource is **registered or exempt with a reason**,
   discovered by scanning `app/Filament/**` rather than from a hand-typed list;
2. all four fields present and non-empty **in both locales** (kept from today's test);
3. **statically**, that each registered screen's page actually mounts `GuideAction` — a string scan,
   not 83 Livewire renders, so the file stays cheap and parallel-friendly;
4. the existing single Livewire smoke test, proving the panel really renders.

EN↔AR key parity comes free: `TranslationKeyConformanceTest` test B already walks every `lang/*` file.

---

## 4. Phase B — field-help triage

| Destination | Use it when | Mechanism |
| --- | --- | --- |
| **`helperText`** (always visible) | It changes what you type or pick — a constraint, a derivation, a consequence. Also **anything computed at runtime** (the `fn (Get $get)` closures in `LeaseForm`): that is live feedback, not explanation. | unchanged, rewritten to ≤ ~12 words |
| **`hintIcon(…, tooltip:)`** | The "why" / background a trained operator does not need on every visit. | `->hintIcon('heroicon-m-question-mark-circle', __('admin.hints.x'))` — signature at `vendor/filament/forms/src/Components/Concerns/HasHint.php:109` |
| **Guide panel** (Phase A) | It is really about the module, not the field. | `guides.php` |

Work:

**What shipped.** The budget is **18 words** (`App\Support\FieldHelp::WORD_BUDGET`) — twelve proved
too tight, turning good one-liners into fragments, and eighteen is where a line becomes a block.
64 strings were over it; **55 were split** and the long half moved verbatim to `admin.hints.*`
behind a `->hintIcon(Heroicon::OutlinedQuestionMarkCircle, …)`.

**Nothing was cut, only moved** — which is also why Arabic needed no new translation for the
tooltips. Only the short visible lines are new prose, and just 21 of those had to be written by
hand; the rest are the string's own opening sentence.

Three findings came from reading each CALL SITE rather than the catalogue:

- **Three strings were already tooltips or modal descriptions** (`statement_consistent`,
  `match_line`, `unmatch_line`) — already the "one hover away" home this work moves things to.
  Shortening them would have lost information and saved no screen space.
- **Five are live feedback, not explanation** — they report the record's state (what is locked, what
  tariff a cost was derived from, what looks mistyped). A hint icon is the wrong home for a message
  shown only when it applies.
- **`charge_code_vat_override` was referenced nowhere** — a leftover of the charge-code VAT override
  the dated tax catalogue replaced. Deleted rather than given a shorter version of a string nothing
  renders.

The one pre-existing `hintIcon` (`ChargeCodeForm.php`) passed **no tooltip** — an icon inviting a
hover that answers nothing. Removed in favour of the real one.

**Gate:** `FieldHelpConformanceTest` — (A) length budget, with `FieldHelp::LONG_BY_DESIGN` carrying a
reason per exemption; (B) no hint icon without a tooltip; (C) every hint is both written and shown,
in both directions — an unwritten one renders the raw key on hover; (D) an exemption whose string is
already short is rejected, so the registry cannot fill with decisions that classify nothing.

---

## 5. Phase C — handbook shell: bilingual, RTL, generated data

**`docs/visual/.vitepress/config.mts`** gains `base: '/handbook/'`, an `outDir` outside the webroot,
and:

```ts
locales: {
  root: { label: 'English', lang: 'en-US' },
  ar:   { label: 'العربية', lang: 'ar', dir: 'rtl', themeConfig: { /* nav, sidebar, outline, search */ } },
}
```

**RTL** is done by converting `theme/custom.css` to **CSS logical properties** rather than by adding
`postcss-rtlcss` — VitePress's own docs call the plugin route experimental. The audit found exactly
**four** physical properties in 216 lines (`border-left`, one `text-align: left`, two
`text-align: right`), so flipping them to `border-inline-start` and `text-align: start/end` is a real
fix rather than a mirror bolted on top. No plugin needed; the file now says so, because a new
`left`/`right` is what would bring the plugin back.

**`atriom:dump-handbook-data`** — a new command in the family of `atriom:dump-system-census` /
`atriom:dump-registries` / `atriom:dump-admin-manifest`. It writes
`docs/visual/.vitepress/data/*.json` from the real registries:

`LedgerPoster::JOURNALIZERS` · `PropertyIsolation` · `DeletionPolicy` · `PostingDateGuards::GUARDS` ·
`ChangeImpact::POLICY` · `SearchPolicy` · `ConcurrencyPolicy` · `ActionAuthz` ·
`RolesPermissionsSeeder` role→permission grants · the scheduled commands in `routes/console.php` ·
the module census.

Five datasets ship: `gl-sources` (24), `posting-roles` (48), `isolation` (99), `workflows` (3) and
`screens` (83).

**What is deliberately NOT dumped: the debit/credit lines each journalizer posts.** They are not a
registry and not statically derivable — `InvoiceJournalizer` resolves its revenue role per line
through `ChargeCode::roleFor()`, i.e. from a table an accountant maintains at runtime, with a
hard-coded floor behind it. Any "map" of them would be a plausible guess rendered as a diagram,
which is worse than not drawing it. The T-account cards stay hand-authored, where worked numbers
back them. The posting explorer answers what IS derivable and is the better question anyway: *what
posts, when is it dated, may it be edited afterwards, and can it be deleted.*

`HandbookDataConformanceTest` re-runs the generator and fails on drift — the same teeth
`GeneratedDocsConformanceTest` gives the markdown docs — and additionally pins that no money record
ever dumps as deletable. That last one is a regression guard on a real defect: both deletion
registers are keyed BY CLASS with the remedy as the value, and the first version searched them with
`in_array($model, …)`, which compares against the REASONS. Every money record dumped as freely
deletable, and the handbook would have drawn the exact inverse of this project's most-stated
invariant, confidently.

**This is what keeps the handbook cheap to maintain: everything that can go stale is derived.**

---

## 6. Phase D — interactive components

Vue SFCs in `.vitepress/theme/components/`, registered via `enhanceApp` in `theme/index.ts` so they
work in any markdown file in either locale. Each takes its UI strings from a per-locale map, so the
Arabic pages render Arabic widgets rather than English ones.

**Two kinds, and the difference is the point.** DERIVED components read the generated datasets and
cannot describe a system that does not exist. ILLUSTRATIVE ones mirror a single line of arithmetic
so a reader can change a number and watch the answer move — each names the class it mirrors and says
that class is the authority. Nothing in between: a component that half-reimplemented a service would
be a second opinion about the same money.

| Component | Kind | What it does |
| --- | --- | --- |
| `<PostingExplorer>` | derived | 24 GL sources: what posts, when it is dated, whether the date is guarded, what may be edited afterwards, and whether it can be deleted |
| `<StateMachine>` | derived | Tenant request / work order / purchase request — click a state for its exits; a state with none is an end |
| `<PercentageRentCalculator>` | illustrative | Natural vs artificial breakpoint, the pair operators most reliably get backwards |
| `<VatRateResolver>` | illustrative | Why a back-dated invoice keeps the rate that was in force — move the document date across a rise |

Superseded design notes:

| Component | What it does | Backed by |
| --- | --- | --- |
| `<PostingExplorer>` | Pick a document type → see the journal entry it produces | journalizers dump — cannot describe a source the code does not post |
| `<FlowMap>` | The system map, clickable: pick a module → what feeds it, what it feeds | module census |
| `<StateMachine>` | Invoice / lease / request / work-order states; click a state for its exits and what it posts | hand-authored per module, linked to the model |
| `<RoleMatrix>` | Role → permissions, filterable | `RolesPermissionsSeeder` dump |
| Calculators | Dated VAT (`Vat::rateForType`), proration (`monthsCovered`), CAM share, percentage rent (natural vs artificial breakpoint), late fee, depreciation | each cites the class it mirrors |

---

## 7. Phase E — 36 module pages × 2 languages

One page per module in `docs/modules/`, **distilled rather than ported** — those files total ~17k
lines and stay the deep reference. Shape of each page:

purpose → the flow, drawn → the rules that surprise people → what it posts to the GL → what it
affects elsewhere → link to the deep doc.

Same facts as the Phase A guides, expanded and illustrated. The sidebar keeps the existing families
and gains an "Every module" section.

---

## 8. Phase F — serve it, build it on deploy

- **`App\Http\Controllers\HandbookController`** + `Route::get('/handbook/{path?}')->where('path', '.*')`
  in `routes/web.php`, behind `auth` — the admin panel uses the default `web` guard
  (`AdminPanelProvider` sets no `authGuard`). It resolves directory paths to `index.html`, sends the
  right MIME types, and **refuses path traversal** (the realpath must stay inside the build dir).
- Build output gitignored, like `/public/build`.
- A link into it from the admin panel user menu, so it is discoverable.
- `npm run docs:build` added to the deploy sequence in
  [PRODUCTION-RUNBOOK.md](../PRODUCTION-RUNBOOK.md), beside the `npm run build` step already there.
- Tests: unauthenticated redirects · authenticated serves the index · `/handbook/ar/` serves the
  Arabic build · `..` is refused.

---

## 9. Verification

```bash
vendor/bin/pest tests/Feature/Scenarios/ScreenGuideConformanceTest.php \
                tests/Feature/Scenarios/TranslationKeyConformanceTest.php \
                tests/Feature/Scenarios/FieldHelpConformanceTest.php \
                tests/Feature/Scenarios/GeneratedDocsConformanceTest.php \
                tests/Feature/Handbook --parallel

php artisan atriom:dump-handbook-data   # must leave the tree clean on a second run
npm run docs:build                      # both locales build
npm run docs:dev                        # eyeball EN + AR/RTL, click every component
```

Plus a manual pass in the panel: open a form in each Phase-B module group in **both** locales and
confirm the field reads cleanly, the hint icon opens, and the guide button shows the right screen.

---

## 10. Sequencing

**A** is the largest single block of writing (68 screens × 4 fields × 2 languages) and it feeds **E**,
so it goes first. **B** is independent and can land alongside it. **C → D → F** is the site spine and
is proved end-to-end with two module pages before **E** fills in the other 34. Each phase is its own
commit with its gate turned on, so nothing lands half-enforced.

| Phase | Scope | Gate | Status |
| --- | --- | --- | --- |
| A | Guides on all **83** screens, EN + AR | `ScreenGuideConformanceTest` (replaces the old one) | **DONE** |
| B | 64 paragraphs → 55 hint icons + 8 reasoned exemptions | `FieldHelpConformanceTest` (new) | **DONE** |
| C | Bilingual/RTL shell + 5 generated datasets | `HandbookDataConformanceTest` | **DONE** |
| D | 4 components: 2 derived, 2 illustrative | server-render probe in the build | **DONE** |
| E | Module reference + **26 pages per locale at full parity** | tree-diff parity check; AR sidebar lists only translated pages | **DONE** |
| F | `/handbook` behind auth + deploy wiring | `tests/Feature/Handbook` (5 tests) | **DONE** |
