# The in-app assistant — design

**Status: THE A PHASE SHIPPED, AND B0 SHIPPED SWITCHED OFF (2026-09-01).** The wording layer is
built, tested and inert: `ASSISTANT_DRIVER=none` is the default, so nothing leaves the server and
nothing is billed until somebody sets a key. B1 (giving the model tools) is NOT built and should
not be, until the miss list says retrieval is the limit. The question box is live at
`/admin/ask` — see [modules/39](../modules/39-assistant.md). No language model is involved and no
Anthropic dependency is in `composer.json`; every B phase below is still a decision, not a plan.

**What it is.** One box in the panel where an operator types a question in Arabic or English and
gets an answer. Two kinds of question, and the design turns on the fact that they are different:

1. *"How does this work?"* — «izzay a3mel credit note?», "what happens if I void this invoice?",
   "which screen sets the late-fee cap?" — answered from the documentation and the screen guides.
2. *"What do the numbers say?"* — "how much is Zara owing?", "which leases expire in Q4?",
   "what was the service-charge recovery in Mall A last month?" — answered from the database.

---

## 1 · The one decision that matters: no text-to-SQL

The obvious build is: give the model the schema, let it write SQL, run it, read the rows back.
**Do not build that here.** It is the wrong answer in this codebase for four independent reasons,
and the fourth is the one the client will care about.

**It bypasses every gate this system has.** A raw query does not go through
`Resource::getEloquentQuery()`, so it does not carry property isolation, it does not carry
`AssignedAssets::idsFor()`, and it does not carry `TenantVisibility::visibleToTenant()` — so a
`viewer` pinned to one mall asks a question and reads the other mall's rent roll, and a draft invoice
that no tenant may see comes back in a list. Every one of those is an invariant this project has a
conformance gate for. A SQL tool is a hole straight through all of them, in a shape no existing gate
can see.

**It produces a second truth about money.** `Invoice::recomputeTotals()` is the single source of
truth for what is settled, and it counts four channels. A model writing `SELECT SUM(total) -
SUM(paid_amount)` gets a number that is *plausible and wrong* — it misses the netted deposit, the
applied tenant credit, and the write-off netting in `InvoiceSettlement::settleableAmount()`. A
chatbot that quietly disagrees with the AR aging screen is worse than no chatbot, because somebody
will act on it.

**It cannot be tested the way this project tests things.** Every registry here has a gate that fails
the build. There is no gate you can write for "the SQL the model will write tomorrow".

**And it is the expensive option.** 159 tables at roughly 15 columns each is 25–40k tokens of schema
in *every single request*, plus few-shot examples, before the question is even read. The tool design
below fits in about 12k and caches. **The safe design is also the cheap one** — that is not a
coincidence, it is what happens when you let code that already exists do the work.

### What to build instead

**The assistant picks a tool and its parameters; it never writes a query.** And the tools are not
new data paths — they are the report and search layers this system already has, called headlessly.

The seam already exists and is already audited. `App\Services\Reports\DeliverSavedReportService`
renders a report page with no browser: authenticate as the person, set the Filament tenant, check
that person's own `canAccess()`, `mount()` the page, apply parameters, read `reportCsv()` back. That
is exactly what the assistant needs, minus the mail, and *easier* — in a web request the user is
already authenticated and the tenant is already set.

There are **25 catalogued reports** in `App\Support\ReportCatalogue::REPORTS` and **19 of them**
implement `DeliverableReport` (the other six are named in `NOT_DELIVERABLE` with a reason — two floor
plans, a diagram, a checklist, a dry run and a PDF pack). Nineteen headless, scoped, permission-checked
answer sources, already built, already correct, already exported by operators every month.

---

## 2 · The tool surface

Five tools. All read-only. All executed as the signed-in user with the current property.

| Tool | What it does | Built on |
|---|---|---|
| `explain_screen(screen)` | The four-field guide for one of the 110 screens — purpose · steps · affects · rules — in the reader's language | `App\Support\ScreenGuides` + `lang/{en,ar}/guides.php` |
| `search_docs(query)` | Top-N passages from `docs/`, with the file path so the answer can cite | new `assistant_doc_chunks` table + `SearchText` folding |
| `run_report(report, parameters)` | Runs one of the 19 deliverable reports and returns its rows | `DeliverableReport::reportCsv()`, the `DeliverSavedReportService` pattern |
| `find_records(resource, query)` | Finds a tenant / lease / invoice / unit by name, number or Arabic spelling | the existing folded `search_text` blob |
| `open_record(type, id)` | An allowlisted field projection of one record | new `App\Support\AssistantFields` registry + gate |

A sixth, `count_and_group(resource, filters, group_by, aggregate)`, is the escape hatch for a
"how many X by Y" that no report answers. It is a **structured** builder over the resource's own
`getEloquentQuery()` — column names validated against the real schema and `ValueSets`, aggregates
from a fixed list — not a SQL string. Build it in phase 3, only if the real questions demand it.

### Three rules the tools must obey

**The tool list is IDENTICAL for every user; the refusal happens inside the tool.** This is the same
rule the panel already lives by — *`visible()` is not an authorization gate* — and here it earns its
place twice. It is the correct security posture (a narrowed list is not a gate; the model can name
any tool it likes), and it keeps the cached prefix byte-identical across all fourteen roles and every
mall, which is where most of the cost saving comes from. A refused call returns a `tool_result` with
`isError: true` saying *"you do not have access to that report"*, and the model words it.

**No writes. Not in v1.** No creating an invoice, no changing a status, no sending an e-mail. Every
write in this system goes through a single-action service plus an `->authorize()`, and the
`AuthorizedAction` container binding that provides the second layer only applies inside Filament —
it does not cover a service called from a chat turn. When a write verb is eventually wanted, it goes
through the same service the button goes through, with an explicit human confirmation step, and it
is its own project.

**The model never does arithmetic on money.** It reads figures the report computed and words them.
If a question needs a subtraction the report does not do, the answer is a link to the screen, not a
sum. This is what makes a cheaper model safe on financial questions: the numbers are deterministic,
and the model is only doing language.

---

## 3 · How the "how does it work" half is answered

Three tiers, cheapest first. **Note there is no vector database anywhere in this design.**

**Tier 0 — always in the cached prefix (~8–12k tokens).** A generated map: the 110 screen names with
their one-line `purpose`, the module index, the 25 reports with the `keywords` already recorded in
`ReportCatalogue`, the module on/off state from `Modules`, and a short glossary (CAM, صيانة /
assessment, holdover, true-up, PDC). This alone answers *"where do I do X"* with no retrieval at all,
and it is what lets the model choose the right tool for everything else. Generated by a new
`atriom:dump-assistant-index`, in the house style — **never hand-typed**, for the reason
`GeneratedDocsConformanceTest` exists.

**Tier 1 — `explain_screen`.** `lang/en/guides.php` is 129 KB and `ScreenGuideConformanceTest`
already fails the build on a screen with no guide. It is bilingual, curated, maintained, and written
to answer exactly *"what am I looking at and what happens if I touch it"*. **This is the single most
valuable asset for this feature and it already exists.** Most how-does-it-work questions should end
here, at one tool call and no search.

**Tier 2 — `search_docs`.** For the deep questions Tier 1 does not reach: the module docs are 1.77 MB
of markdown and the six root docs another 567 KB. Pre-chunk by heading into a table, index with
MySQL FULLTEXT, and **fold both sides through `App\Support\Search\SearchText`** — the rule this
project already enforces for every other search, and the thing that makes «شركة» match «شركه».

### Why not embeddings

Anthropic publishes no embeddings endpoint, so embeddings mean a second vendor, a second API key, a
vector store, and a re-index pipeline that goes stale silently. The corpus here is small, the
vocabulary is domain-specific and consistent, and — decisively — a curated Tier 0 map plus a maintained
Tier 1 corpus removes most of the retrieval problem before it starts. Start without them.
If measurement later shows keyword search missing real questions, add embeddings then, with evidence.

---

## 4 · Model and cost

Pricing below is Anthropic first-party, from the reference bundled with this session (cached
2026-06-24). Cache reads cost ~0.1× the input rate; cache writes 1.25× at the 5-minute TTL and 2× at
the 1-hour TTL.

| Model | Model ID | Input / Output per 1M |
|---|---|---|
| Claude Opus 5 | `claude-opus-5` | $5 / $25 |
| Claude Sonnet 5 | `claude-sonnet-5` | $2 / $10 |
| Claude Haiku 4.5 | `claude-haiku-4-5` | $1 / $5 |

**Per question**, assuming a 12k cached prefix, ~3k of fresh tokens (question + tool results), ~700
output tokens, and two round trips for a data question (one to choose the tool, one to answer):

| Model | Docs question | Data question | 3,300 questions/month |
|---|---|---|---|
| Haiku 4.5 | ~$0.004 | ~$0.009 | **~$31** |
| Sonnet 5 | ~$0.008 | ~$0.019 | **~$63** |
| Opus 5 | ~$0.020 | ~$0.047 | **~$155** |

3,300/month is 15 operators asking 10 questions a day, 22 working days. **Even the most expensive
row is under $2,000 a year.** The cost question here is not really a cost question — it is a
correctness question wearing a cost question's clothes, and the architecture above is what decides
both.

**Recommendation: start on one model — Sonnet 5 — at `effort: low`.** Not a cascade.
Caches are model-scoped, so routing across two models forfeits cache reuse on whichever one is not
warm, and the measured advice is to try the capable model at low effort *before* building a cascade.
One model is also one thing to evaluate. If the logs later show that 70% of traffic is Tier-1 screen
questions, move exactly that route to Haiku 4.5 and keep its own cache warm.

Opus 5 is the default this project would otherwise take, and it is your call — the reason I am not
recommending it as the starting point is that on this design the model is doing language over
figures that code already computed, which is the workload where the gap narrows most.

### Where the savings actually come from, in order

1. **The architecture above.** Tools instead of SQL removes ~30k tokens of schema per request.
2. **Prompt caching, 1h TTL, one namespace.** Put the frozen system prompt, the tool definitions and
   the Tier 0 map before the breakpoint; put the user's name, role, property and question *after* it.
   Do that and all fourteen roles across every mall share one cache entry. Get it backwards and you
   have twenty entries, each paying its own write. Verify with `usage.cacheReadInputTokens` — if it
   is zero on repeated questions, something in the prefix is moving (a timestamp is the usual
   culprit). *Note the 1h TTL doubles the write and needs three reads to break even; on a quiet
   install the 5-minute TTL is the better default.*
3. **An answer cache.** Hash the folded question + role + property + a data-version stamp. Pure-docs
   questions repeat constantly across an office — «izzay a3mel credit note» will be asked by six
   people. The cheapest question is the one that never reaches the API.
4. **Cap the tool results.** 200 rows or ~30k characters, whichever comes first, then *"1,240 more
   rows — here is the screen"* with a deep link. An unbounded rent roll in a tool result is the one
   thing that can make a single question cost a dollar.
5. **Batch API (50% off)** for anything non-interactive, if a nightly digest is ever wanted.

---

## 4b · The zero-cost tier — and where the "near-free" number actually comes from

The costs in §4 assume the model does the *choosing*: it reads a 12k index of screens and reports and
decides which tool to call. **That index is the expensive part, and it is only there because the
model is doing the retrieval.** Take the retrieval away from the model and the whole economic shape
changes.

### Tier A — $0. No LLM at all.

A large share of what was asked for is a **search and routing** problem, not a language-model problem,
and this codebase is unusually well equipped for it:

- **112 screens** have a four-field guide in **both** languages (`lang/{en,ar}/guides.php`).
- **26 reports** already carry hand-written `keywords` in `ReportCatalogue::REPORTS` — including
  Arabic ones (`إهلاك`, `بند`, `موقف`, `كشك`).
- `App\Support\Search\SearchText::normalize()` / `::words()` already folds Arabic spelling variants
  on both sides of a query.
- `AtriomGlobalSearchProvider` already finds any record, scoped and permission-checked.

So: an "Ask Atriom" box that folds the question, scores it against the screen-guide corpus + the
report keywords + the doc chunks, and returns **the guide, the report (pre-filtered), or the record**.
Not a chat — a very good answer box. It handles *"where do I set the late-fee cap"*, *"open the AR
aging for Zara"*, *"what does Occupancy Cost mean"* exactly, in Arabic, for **zero marginal cost and
zero external dependency**.

**What it cannot do**, and this is the honest limit: it cannot handle a question phrased in a way
nobody anticipated, it cannot combine two facts into one sentence, and it cannot say *"you can't,
because the period is closed — reverse it instead"*. It retrieves; it does not explain.

### Tier B — near-free. The LLM only WORDS the answer.

Keep Tier A as the retriever, and call the model **only when the search misses or the operator asks a
follow-up**. The prompt is then not a 12k index — it is ~1k of instruction plus the ~2.5k passage
Tier A already found. The model's job drops from *"reason over 25 reports and 112 screens"* to
*"answer this question from this passage, in Arabic"*, which is a task Haiku 4.5 does well.

| | Prompt | Per question | 3,300 q/month, ~35% reaching the model, ~40% of those answered from the answer cache (~700 calls) |
|---|---|---|---|
| **Tier A only** | — | **$0** | **$0** |
| Tier B on Haiku 4.5 | ~3.5k in / 500 out | ~$0.006 | **~$4 / month** (~200 EGP) |
| Tier B on Sonnet 5 | ~3.5k in / 500 out | ~$0.012 | **~$8 / month** (~400 EGP) |
| Everything through the model (§4 design) | ~12k cached | ~$0.019 | ~$63 / month |

**That is the whole finding: moving retrieval out of the model is worth roughly 15× more than
choosing a cheaper model.** And a hard monthly cap in settings makes the ceiling a decision rather
than a surprise — at $0.006 a question, a 1,000-call ceiling is $6.

*At low volume, turn prompt caching OFF.* A cache write costs 1.25× and a read 0.1×, so it needs two
hits inside the five-minute window to pay for itself. On a quiet install the cached prefix is written
far more often than it is read, and caching then costs **more** than not caching. It is a
high-traffic optimisation; the answer cache in §4 is the low-traffic one.

### Tier C — another provider's free tier

Several providers offer a genuinely free API tier with rate limits. Three things make them a poor fit
for *this* application specifically, and they should be stated rather than discovered:

- **The data.** Every question carries another company's financial position — a retailer's arrears, a
  supplier's rates, an employee's payslip. Free tiers commonly come with weaker data-handling
  commitments than paid ones, and several train on submitted data by default. This system already
  holds itself to a PDPL-shaped standard for the audit trail; sending tenant AR to a free tier for a
  saving of roughly 200 EGP a month is not a trade this project should make quietly.
- **Rate limits bite exactly when it matters.** Month-end is when everyone asks at once.
- **It can be withdrawn.** A free tier is not a contract.

**A ChatGPT / Claude / Gemini *subscription* is not an API, and cannot be used here.** A consumer
subscription buys one named person a seat in a chat window. It issues no API key, and there is no
supported way for a Laravel application to call it — accounts are per-person under every provider's
terms, so it cannot serve fifteen operators either. The unofficial routes (browser automation,
reverse-engineered endpoints) break constantly, breach those terms, and would push tenant financial
data through a channel with no data agreement behind it: not a thing to do in an ERP. Programmatic
access is always a **separate, per-token API account with its own billing.** Where a subscription
genuinely does help is at *build* time — drafting doc chunks, the Arabic wording, and the ~50-question
evaluation set — which is worth real money and costs nothing extra.

One practical note for an Egyptian operator: every one of these APIs bills in USD and needs a
card that will take a foreign recurring charge. If that is the friction rather than the amount,
Tier A is the answer twice over — no vendor, no card, no forex.

If it is still wanted, the mitigation is architectural and costs nothing to build in now: put the
model behind an `App\Contracts\AssistantModel` interface with a `none` driver (Tier A), an
`anthropic` driver, and room for a third. The provider then becomes a config line, not a rewrite.

### Tier D — self-hosting an open model

**This is not the cheap option, and it is worth saying plainly.** A 7–8B model on the CPU of the
existing server answers in 30–60 seconds, is materially weaker at Arabic, and is unreliable at
following instructions about what it may not say. A model large enough to be trustworthy here needs a
GPU box at roughly $150–400/month — **more than Claude Opus 5 would cost at this volume.**
Self-hosting only becomes cheap if the hardware already exists and is otherwise idle.

### Recommendation

**Build Tier A first, ship it, and measure.** It is phases 0–2 of §6 with the API calls removed, it
costs nothing to run, it depends on no vendor, and it may well be enough — the logs will say. Then
add Tier B as the fallback wording layer on Haiku 4.5, behind a monthly ceiling. The realistic
steady-state bill is **0–1,000 EGP a month**, and the first number is achievable.

---

## 5 · Fitting the house rules

This is a module in a codebase with strong opinions. It has to arrive wearing them.

**A switch.** `Modules::KEYS` gets `assistant`, in a `Modules::GROUPS` section, so it is switchable —
and only by super_admin, like every other module switch.

**A configuration-health row, not a screen.** `App\Support\ConfigurationHealth` is the registry that
answers *"is this install SET UP"*, and it is what `atriom:preflight` reads. A missing
`ANTHROPIC_API_KEY` with the module ON is a BLOCKING row there. It must not be a health row — the
API being reachable is liveness, having a key is configuration, and this project already learned
what happens when the two get confused.

**A permission.** `assistant.use`, seeded in `RolesPermissionsSeeder` — **and seeding is a deploy
step**, or the screen is invisible to everyone including super_admin.

**Property isolation and deletion policy.** The two new models (`AssistantConversation`,
`AssistantMessage`) need a `PropertyIsolation` classification and a `DeletionPolicy` attribute or the
build fails, correctly. A chat log is `#[DeletionAllowed]`; it is also transient, so it belongs in
`HousekeepingSettings` and `atriom:prune-transient-data` with its own retention period.

**Bilingual, and the answer follows the READER.** Same rule as the PDFs: resolve the locale, clamp it
to `SetLocale::SUPPORTED`, and wrap the whole turn — the tool results carry `__()`-derived labels, so
a wrapper around the final message alone yields an Arabic answer over English column headings.

**A refusal is a `DomainException`.** Over budget, module off, no permission: throw, and it renders
as a toast in the operator's language via `admin.refusals.*`. And it must be translated —
`RefusalsAreTranslatedConformanceTest` will insist.

**Every turn is logged as DATA.** `ActivityLogging` + `ActivityVocabulary`: the question, the tools
called and their parameters, the token counts and the cost. Not a rendered sentence. That log is the
only way to answer "which questions is it getting wrong" and "what is this costing", and it is also
the evidence trail for a system that reads tenant financial data.

**Prompt injection is real here, because the data is operator-typed.** Tenant names, lease notes,
work-order comments and vendor documents all flow back through tool results, and every one of them
is a string somebody outside the company could have influenced. Three defences: the assistant has no
write tools, so the worst case is a wrong sentence rather than a wrong record; tool results are
fenced and labelled as data in the prompt; and — the real one — every tool re-checks authorization at
execution, so a successful injection still cannot reach a row the user could not open. That is the
same reasoning as the vendor portal's third layer.

**Budget ceilings, enforced server-side.** A per-user daily token cap and an org monthly cap, both
settings. Without them a loop or an enthusiastic operator is an uncapped bill.

---

## 6 · Build order

Each phase ships and is used before the next starts. That is deliberate: the questions people
actually ask will be different from the questions we predict, and the A-phase logs should decide
whether the B phases happen at all.

**Phases A run at zero cost and pull in no vendor. Phase B is the only one that spends money, and
by then the logs say whether it is needed.**

| Phase | Scope | Cost to run | Rough size |
|---|---|---|---|
| **A0** ✅ | The "Ask Atriom" box: fold the question with `SearchText`, score it against the 112 screen guides and the 26 reports' `keywords`, show the guide. Module switch, permission, activity logging. | $0 | 2 days |
| **A1** ✅ | Route a question to a **record** through `AtriomGlobalSearchProvider`, and to a report at the **year** it named. Deep links, not answers. Note: no report declares a tenant parameter, so "pre-filtered" means period, never counterparty. | $0 | done |
| **A2** ✅ | `atriom:rebuild-assistant-index` + the doc-chunk table, so the box reaches past the guides — 530 sections, 405 EN / 125 AR. Two corrections to this row: it is a **deploy** step, not a nightly one (the files change only when the repo does), and it uses the project's own folded `LIKE` rather than FULLTEXT, which is driver-specific and this suite runs on SQLite. | $0 | done |
| **A3** ✅ | The miss list as a screen — `/admin/assistant-questions`, grouped and ranked, with its own `assistant.review` permission. | $0 | done |
| **—** | **Ship, and read the miss list for a month.** What was asked, what matched nothing. That list is the only honest input to the next decision, and it no longer needs a database client to read. | $0 | — |
| **B0** ✅ | The model as a **wording layer only**, on the passage A0–A2 already found: `AssistantModel` interface, `none` + `anthropic` drivers, monthly ceiling, answer cache. Ships OFF. | 0 EGP until enabled, then ~200 EGP/mo | done |
| **B1** | Give the model the tools (`run_report`, `find_records`, `open_record`) so it can answer what retrieval alone cannot. Row caps, `AssistantFields` registry + gate. | ~400–1,000 EGP/mo | 3–4 days |
| **B2** | Evaluation set of ~50 real questions from the A-phase logs, with expected answers. Run before each release. | — | 2 days |
| **later** | The tenant portal assistant — scoped to one tenant, no cross-tenant reach, drafts hidden. Highest risk surface; do it last, on its own. | — | — |

**B2's evaluation set is not optional garnish.** A chatbot has no failing test to tell you it
regressed. Fifty real questions with expected answers, run before each release, is the only thing
between "it works" and "it worked in August".

---

## 7 · What this deliberately does not do

- **No writes**, so no "raise the invoice for me". Later, with confirmation, through the real service.
- **No raw SQL**, for the four reasons in §1.
- **No arithmetic on money.** Figures come from reports; the model words them.
- **No vector database** until keyword retrieval is measured and found wanting.
- **No cross-property reach.** The assistant sees exactly what its operator sees, through the same
  `getEloquentQuery()`. If the answer needs another mall, the answer is "switch property".
- **No second documentation set.** It reads `docs/` and `guides.php`. A corpus maintained *for the
  bot* would drift from the one maintained for people, and the bot would then confidently teach the
  stale one.
