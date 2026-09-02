# 39 · Ask Atriom — the in-app assistant

**Status: the A phase shipped, and phase B0 shipped SWITCHED OFF.** With the default
`ASSISTANT_DRIVER=none` no language model is involved and nothing leaves the server. See
[docs/integrations/AI-ASSISTANT.md](../integrations/AI-ASSISTANT.md) for the full design, the later
phases, and what a model would cost if one is ever added.

---

## Purpose

One box an operator types a question into, in Arabic or English, that points at the screen or report
which answers it.

The two questions an operator has are *"how do I do X"* and *"what do the numbers say"*. This system
already answered both — 113 screens carry a four-field guide in two languages, 26 reports carry
curated keywords — and there was no way to **ask**. Finding the answer meant already knowing which
screen held it, which is precisely what a new operator does not know.

## Domain model

| Thing | Where |
|---|---|
| `AssistantQuestion` | `assistant_questions` — what was typed, and what it matched |
| `AssistantCorpus` | `app/Support/Assistant` — the searchable index, **derived** from two registries |
| `AssistantEntry` | one screen or report, with its folded word → weight map |
| `AssistantRecords` | finds the RECORDS a question named, through the global search |
| `AssistantDocChunk` | `assistant_doc_chunks` — the operator-facing documentation, chunked by heading |
| `DocCorpus` | WHICH documentation may be quoted, and the chunker |
| `AssistantDocs` | the documentation tier — consulted only when the guides had nothing |
| `AssistantModel` (contract) | the optional wording layer — `none` and `anthropic` drivers |
| `AssistantBudget` | month-to-date spend against a hard ceiling |
| `AnswerQuestionService` | `app/Services/Assistant` — fold, score, filter by access, record |
| `Assistant` (page) | `/admin/ask` — the question box |
| `AssistantQuestions` (page) | `/admin/assistant-questions` — the miss list |

**The corpus is derived, never listed.** `ScreenGuides::SCREENS` and `ReportCatalogue::REPORTS` are
the sources, and both already have conformance gates forcing completeness — so a new screen becomes
searchable the day somebody writes its guide, with no second registry to forget.

## Business rules & invariants

- **It can never name a screen its reader may not open.** Every candidate is filtered through that
  screen's own `canAccess()` inside the service. Two roles asking one question get different
  answers, correctly — a link that 403s reads as a broken system rather than as a boundary.
- **A question naming a record answers with that record, first.** "How much does Cilantro owe"
  leads with the tenant, then the AR aging screen — a question naming something specific is asking
  about that thing, and the screen explaining the concept is the follow-up.
- **Records come from the global search, not from a query of our own.**
  `AtriomGlobalSearchProvider` already runs `canGloballySearch()` (→ `canAccess()`) and scopes
  through `getEloquentQuery()`, so property isolation and authorization are inherited by
  construction. A second search here would be a second thing to keep in step.
- **One destination, one card.** A page can be both a guided screen and a catalogued report; the
  two entries are merged, keeping the screen's identity (its key resolves the guide) and the better
  URL (the report's, when a year was named).
- **It points at answers; it does not compute them.** A money question leads to the report that
  produces the figure, so the number the reader sees is the number the report shows. Nothing here
  re-derives a balance, which would be a second truth about the same money.
- **Both languages, and a query typed in the other one still works.** The corpus is per-locale; the
  reader's own locale is tried first and wins ties; only if nothing clears the floor are the other
  `SetLocale::SUPPORTED` locales tried. A match returns a **key**, and the guide is resolved from
  that key at render time — so a cross-locale match never produces a half-translated page.
- **Every question is recorded, answered or not.** The unanswered list is the deliverable of this
  phase.
- **Read-only.** No write path, no external call, no data leaves the server.

## Ranking

Three weights and nothing else, kept legible so a wrong answer can be explained:

| Signal | Weight |
|---|---|
| A screen's title / a report's curated keyword | 8 |
| A word in the guide's `purpose` | 3 |
| A word anywhere else in the guide | 1 |

Highest weight per word wins, never the sum — otherwise a word repeated eleven times in a long guide
out-ranks the same word in a title, and length beats relevance.

**The floor is 6** — one title/keyword hit, or two independent purpose hits. It was 3, and measuring
against the demo books moved it: *"where do I set the late fee cap"* answered **AR aging** and
«مين عليه فلوس» answered **Credit notes**, both on a single common word landing in a purpose
sentence, presented with the same confidence as a title match scoring 16. **A wrong first result is
worse than none** — the reader follows it, finds the wrong screen, and concludes the box does not
work. Silence is honest, costs one more search, and lands the question on the unanswered list where
it becomes the next screen guide.

## Phase B — the model as a WORDING layer

**It ships off.** `config/assistant.php` defaults to `driver => none`, which binds
`NullAssistantModel` and makes the whole A phase the shipped behaviour. Turning it on is one env
line plus a key.

**It never chooses anything.** Retrieval has already found the passages — filtered by what this
reader may open — and the model is handed those and nothing else. No tools, no database handle, no
say in what it sees. So it cannot reach a record its reader could not, cannot run a query, and
cannot move anything: the worst outcome available to it is a wrong sentence printed above the
correct source, which stays on screen underneath it.

That is also why it is affordable. The expensive design is the one where the model reads a
catalogue of 26 reports and 113 screens and decides; this one reads three paragraphs — about
**$0.006 a question**, ~200 EGP/month for an office of fifteen.

**Four cost controls, in the order they bite:**

1. `driver => none` — the default. Costs nothing.
2. The **answer cache**, keyed on the FOLD of the question *and* on what retrieval found — so six
   people asking one thing six ways pay once, and a re-indexed handbook correctly re-asks.
3. The **ceiling**, checked BEFORE every call so it is a wall rather than a report. Spend is
   derived from tokens recorded on `assistant_questions`, so it survives a cache flush and can be
   audited from the same rows the miss list ranks. `0` means "never spend" — a supported way to
   stop the spending without deleting the key.
4. A small `max_tokens`: a short answer is the product, since the passages are on screen below it.

**Why the ceiling lives in config and not in Settings:** only whoever can deploy can set the API
key, so only they can enable spending at all. A ceiling an operator could raise without being able
to set the key would be a control over nothing.

**Why Haiku 4.5 by default:** on this design the model does not choose, so its whole job is *"answer
this question from this text, in this language"* — the workload where the cheapest current model is
closest to the most expensive, at roughly a fifth of the price. Configurable; the miss list is how
you would know it needed raising.

**Every failure leaves phase A working.** No key, over budget, provider outage, rate limit, a
non-text block first in the response: each returns null, and the screen shows the sources exactly as
it did before phase B existed.

## Two surfaces, one service

| | `/admin/ask` | The floating bubble |
|---|---|---|
| Shows | what retrieval **found** — screens, reports, records, handbook sections, ranked | what the model **said**, with sources shrunk to citations |
| For | looking something up | a conversation |
| Where | its own screen | **every admin page**, via the panel's `BODY_END` render hook |

**Side is not branched.** The bubble sits bottom-**right** in English and bottom-**left** in Arabic
because the view uses `inset-inline-end`, a CSS logical property, and the panel's own `dir` decides
it. There is not one `left` or `right` in `livewire/assistant-chat.blade.php` — the same rule the
handbook theme follows, and the reason it needs no RTL plugin. The bubbles use `ms-auto`/`me-auto`
and the send icon carries `rtl:-scale-x-100`, because an arrow pointing right in an Arabic panel
reads as *back*.

**The model writes every answer on this surface, and retrieval still grounds it.** That is not a
hedge: without the passages it would answer questions about Egyptian VAT, late-fee clauses and this
system's own rules from general knowledge — fluently and wrongly, on a screen where somebody is
deciding what to bill. The citations stay visible so the answer can be checked.

**With a model configured the documentation tier always runs**, rather than only when the guides
found nothing. The fallback rule is right for a LIST — a screen link beats a paragraph — and wrong
for a chat, where more grounding is strictly better and the screen link survives as a citation.

## Switching the model on — the exact steps

**Anthropic has no free tier**, so a demo takes the `openai_compatible` driver. One driver covers
Google Gemini, Groq, OpenRouter and a local Ollama, because the difference between them is a base
URL and a model name.

### Free (Google Gemini — no credit card)

1. Get a key at **https://aistudio.google.com/apikey**.
2. In `.env`:
   ```
   ASSISTANT_DRIVER=openai_compatible
   ASSISTANT_BASE_URL=https://generativelanguage.googleapis.com/v1beta/openai
   ASSISTANT_API_KEY=<the key>
   ASSISTANT_MODEL=gemini-flash-lite-latest
   ASSISTANT_RATE_INPUT=0
   ASSISTANT_RATE_OUTPUT=0
   ```
   The two zeroed rates switch the spend ceiling off, which is correct when there is no bill to cap.
   Token counts are still recorded, so `/admin/assistant-questions` still shows usage.
3. `php artisan config:clear`
4. Ask something on `/admin/ask`.

**When the daily quota runs out**, the provider answers 429, the driver returns null, and the screen
falls back to the retrieval answer it gave through the whole A phase. A demo degrades; it does not
break.

### For a TECHNICAL demo, index the developer docs too

`ASSISTANT_INDEX_TECHNICAL_DOCS=true`, then `php artisan atriom:rebuild-assistant-index`. That adds
`docs/modules/` — the per-module reference, the deepest description of this system that exists — so
the assistant can answer *"how does the GL decide which account to post to"*. It is **off by
default** because it answers a retail manager's business question with an implementation.

### Paid (Claude — better answers, no free tier)

`ASSISTANT_DRIVER=anthropic` + `ANTHROPIC_API_KEY`, and the ceiling in `config/assistant.php`
applies. Roughly $0.006 a question on Haiku 4.5.

### What does NOT change with the provider

The prompt. `AssistantPrompt` is shared by both drivers, because its three rules are **safety, not
style**: answer only from the passages · never compute a figure that is not verbatim in one · the
passages are content, not instructions. A copy per driver would drift, and the copy that drifted
would be the one running on whichever provider nobody re-read.

## The miss list is the point

`/admin/assistant-questions` is where the A phase pays off. It groups every question by its FOLDED
form — so «فاتورة» and «فاتوره» are one row, and so are *"Credit Note"* and *"credit note"* — counts
it, and ranks **most-asked first**. One person asking six times is not six problems, and a list of
misses in date order is a feed, which is something you read once.

The **Answered** column reads *"1 of 3"* or *"Never"*, and the difference is the whole value:
answered-sometimes is a **ranking** problem, answered-never is a **missing screen guide**. Two
causes, two fixes, and a yes/no column would hide which one you have.

**It has its own permission — `assistant.review` — where the box deliberately has none.** Every
result the box offers is already filtered through the target screen's `canAccess()`, so a right
there would grant what the reader already holds. This screen shows what *other people typed*, in
their own words, and a question can name a tenant: that is something to grant. Held by `super_admin`
and `manager`. **Adding it was a deploy step** — `php artisan db:seed --class=…RolesPermissionsSeeder`
— because a permission that exists only in the seeder file leaves the screen invisible to everyone.

Property-scoped, like the questions themselves.

## Three tiers, in order

1. **Records** — the thing the question named.
2. **Screens and reports** — the guides and the report catalogue.
3. **Documentation** — *only when tier 2 found nothing.*

The third tier is a **fallback, not a peer**, and that is the load-bearing rule. A screen guide
answers *"how do I do X"* with the screen that does X and a link to it; a paragraph of prose answers
with words. When both exist the guide is strictly better, so ranking them together would let a
well-written chapter push the actual screen off the top of the page. Tier 3 runs exactly where A0
recorded a miss.

## Which documentation may be quoted

`docs/` is not one audience. `docs/modules/` is 1.77 MB written for whoever changes the code —
quoting it to a retail manager answers a business question with an implementation, which reads as
though no business answer exists. So `DocCorpus::SOURCES` is an **allowlist**, and every top-level
directory under `docs/` is either indexed or in `NOT_INDEXED` **with a reason**;
`TheAssistantReachesPastTheScreenGuidesTest` fails the build on one that is neither, and on a stale
entry.

Indexed: **`docs/visual/`** (the handbook — bilingual, and already published at `/handbook`, so its
chunks carry a real URL) and **`docs/training/`** (the walkthroughs, whose own README says they are
*"written for someone new to the BUSINESS — not to the codebase"*; published nowhere, so the
excerpt **is** the answer rather than a pointer). 530 sections, 405 English and 125 Arabic.

Rebuilt by **`php artisan atriom:rebuild-assistant-index`**, which is a **deploy step in
`deploy.sh`, not a scheduled job** — a correction to the original design. These files change when
the repository changes and at no other time, so a nightly run would rewrite an identical table 365
times a year. It sits beside `atriom:rebuild-search`, which is there for the same reason.

## Which words become a record search

The global search ANDs its words, so handing it *"how much does Zara owe"* matches nothing. The
words worth searching are the ones the **documentation has never heard of**: every word in a guide,
a screen title or a report keyword is vocabulary of the system, so a word appearing in none of it is
far more likely to be a tenant's name, a unit code or a document number. Derived from the corpus,
so there is nothing to maintain, and it degrades safely both ways — a proper noun that happens to
appear in a guide is simply not searched for, and a domain word that appears in none costs one
search the provider's own query floor makes cheap.

**A bare four-digit year is excluded.** It is the one token certainly not a record name, and it is
already read as a report parameter.

## Deep links

A **year** is the only parameter lifted out of a question, and only for a report that declares one:
four digits in a plausible range are unambiguous in both languages, where *"last month"* and
«الشهر اللي فات» are not. **No report declares a tenant parameter at all** — so *"AR aging for
Zara"* links to the record **and** to the report, rather than to a report pre-filtered in a way it
does not support. Even a wrong year is recoverable in a way a wrong figure would not be: it is a
link, and the report shows its own period selector.

## Gotchas

- **A locale argument that does not switch the application locale is decoration.** Every string the
  corpus is built from resolves through `__()` against the *current* locale, so `entries('ar')`
  built the Arabic corpus out of English strings until it wrapped the build in a locale switch with
  a `finally`. The cross-locale fallback then compared English to English and could never find
  anything. Same trap `DocumentLocale::in()` exists for on the PDFs.
- **The stop list is applied to the CORPUS as well as the query, and that is load-bearing.** The
  report hub's own label is *"All Reports"*, so `all` carried **title** weight — the strongest in
  the corpus — and any sentence containing the word got a confident top hit on the report hub. The
  floor cannot catch that; the floor suppresses weak *body* matches. Only removing the term from
  both sides does.
- **`SearchText::words()` does not drop short tokens**, correctly for its own job. Here it means
  *"how do I add a tenant"* scores every guide containing *"do"*. Hence the stop list — kept short,
  because *cost*, *type*, *due*, *paid* and *open* all look generic and all name real things here.
- **Egyptian colloquial is not Modern Standard Arabic.** An operator types «ازاي», never «كيف»;
  «مين عليه فلوس», never «من المدين». Both the stop list and the report keywords carry the
  colloquial forms.
- **The assistant is excluded from its own corpus.** Its guide is written in the vocabulary of
  asking questions, which is the vocabulary every question is made of — left in, it ranked on
  everything, and *"open Ask Atriom"* is a dead end for somebody already looking at it.
- **A bare year was searched as a record and matched three units**, because a unit's search blob
  carries dates — *"income statement 2026"* answered *PA-01, PA-02, PA-03*. Found by driving it, not
  by a test.
- **A test of the record half that does not seed `RolesPermissionsSeeder` returns zero for
  everything**, because Filament drops a result whose URL is blank and the URL derives from
  `canView()`. Every refusal then passes for the wrong reason. This cost one wasted diagnosis here
  and is already recorded for the global search.
- **A translation key built by interpolation is invisible to the parity gate** — it resolves to the
  PREFIX, so every leaf under it is unchecked in both locales. The result-kind labels are spelled
  out for that reason.
- **A documentation match requires EVERY word**, not the best few. Hundreds of pages of prose
  contain almost every common word somewhere, so partial-overlap scoring answered every question
  with the longest chapter. One relaxation pass (all-but-one word) runs only when the strict pass
  found nothing, and carries a penalty so a relaxed hit can never outrank a strict one.
- **`AssistantDocChunk::stem()` widens a word, and is safe only because the blob is matched with
  `LIKE %stem%`** — a shorter stem matches MORE, never fewer, and precision is taken back by the
  all-words AND. It is deliberately NOT applied to the screen corpus, which matches whole words
  against a curated vocabulary where "lease" and "leases" are not the same signal. A separate `es`
  rule was removed rather than reordered: it turned "bounces" into "bounc", and this domain's
  plurals are almost all of nouns already ending in `e`.
- **`|| warn` in `deploy.sh` would have aborted every release.** The script defines exactly one
  helper (`step`) and runs under `set -Eeuo pipefail`, so an undefined function returns non-zero
  and takes the deploy down — over a search index. It is `|| printf`, which always succeeds.
- **A new column must be added to `$fillable` or `create()` drops it SILENTLY.** The model's
  answer vanished and the spend ceiling read zero for ever — the same defect this codebase records
  for `recurring_expenses.recurring_expense_id`. Three tests failed at once, which is only because
  the budget is derived from those columns rather than from a counter.
- **The passages are DATA and the system prompt says so.** They carry operator-typed text — a
  trading name, a lease note, a work-order comment — that a party outside the company could have
  influenced. They are fenced and labelled, and instructions inside them are to be reported rather
  than followed. That is a mitigation, not a guarantee: the real defence is that the layer has no
  tools to abuse.
- **No prompt caching on this route, deliberately.** Haiku 4.5's minimum cacheable prefix is 4,096
  tokens and this request is a fraction of that, so a `cache_control` marker would silently do
  nothing. The answer cache is the lever that pays at this volume.
- **Every non-grouped column in the miss list is an aggregate**, because MySQL runs
  `ONLY_FULL_GROUP_BY` and would reject a bare column while SQLite silently picks an arbitrary row
  and the suite stays green. `MAX(id)` also gives Filament a real record key, so the table
  paginates and sorts like any other.
- **`AuthenticateSession` logs the second user out** when `actingAs` is swapped between two HTTP
  requests in one test: the control then answers a redirect, which blows up inside the session
  middleware as a 500 and makes a working page look broken. `$this->flushSession()` between them.
- **`NullAssistantModel::isConfigured()` returns FALSE**, and the reading matters. It returned true
  on the basis that the null implementation "can run" — it can, it returns null — and every caller
  actually asks *"will something write an answer?"*. It shipped as a defect the moment the chat used
  the same predicate to decide whether to gather extra grounding: with no model at all it said yes,
  and the documentation tier ran on every question, overriding the fallback rule the guides depend
  on. Reading it as *"will this word an answer"* makes all four call sites correct with no
  `instanceof`.
- **A free quota is per model per day.** One afternoon of testing exhausted `gemini-3.6-flash` and
  every later question fell back to sources with a 429 in the log. The default is
  `gemini-flash-lite-latest`, whose allowance is far larger — and on this workload the model is not
  choosing anything, only wording a passage it was handed, which is the task where the cheapest
  model is closest to the best.
- **Arabic morphology is not handled.** «اشعار» does not match «اشعارات»; there is no stemming. So
  «ازاي اعمل اشعار خصم» still answers the withholding-tax return, because خصم means both *credit*
  and *withholding* and the WHT return holds it as a keyword. This is a known, measured limit and
  the miss list is what should decide whether prefix matching is worth adding.

## Access

No permission, deliberately — the same call the Handbook makes, for a stronger reason. Every result
is already filtered through the target screen's own `canAccess()`, so an `assistant.view` right
would grant exactly what the reader already holds and refuse exactly what they are already refused:
the shape this codebase retired 43 `{module}.delete` keys for. What an operator may genuinely want
is to switch the screen **off**, which is `Modules::KEYS['assistant']` (`modules.assistant`,
defaulting on). Declared in `EveryRoleMeetsEveryScreenTest::UNIVERSAL_SCREENS` with that reason.

## Tests

`AskingAtriomFindsTheScreenThatAnswersTest` (18), `TheAssistantReachesPastTheScreenGuidesTest` (9),
`TheMissListIsTheDeliverableTest` (6) and `TheModelOnlyWordsWhatRetrievalFoundTest` (11) — 44 in
all. Phase B is tested through a FAKE implementation of the contract, so the suite spends nothing;
the ceiling, the cache and the default-off were each mutation-proved. Every refusal is
paired with a control that must succeed, and four of the properties were mutation-proved: the floor,
the stop list, the locale switch, and the page's own render.

## Extension points — how to change it safely

- **A screen is found more easily → widen its guide**, not this module. The `purpose` sentence is
  weight 3 per word and is the intended lever.
- **A report is found more easily → add a `keywords` entry** in `ReportCatalogue::REPORTS`, in both
  languages. That list is also what the report hub's own filter reads, so the improvement lands
  twice.
- **A new screen** needs nothing here — write its guide and it is indexed.
- **Changing a weight or the floor**: re-measure against real questions before and after
  (`AssistantCorpus::entries()` plus the service is enough for a scratch script), and add the case
  that moved you to the test file. The floor moved once already and only measurement justified it.
- **A new documentation area** must be added to `DocCorpus::SOURCES` or `NOT_INDEXED` with a
  reason — the gate will insist.
- **Do not add a second corpus.** If the guides are thin, the fix is the guides.
