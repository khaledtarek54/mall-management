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

## The words operators actually use

Ranking cannot fix a vocabulary gap. Thirty-three real operating tasks were taken from
[docs/training/OPERATOR-PLAYBOOK.md](../training/OPERATOR-PLAYBOOK.md) — the documented daily,
weekly, monthly and yearly rhythm — and driven through retrieval with no model attached. All 33
returned something and **seven went confidently to the wrong place**: *"record a receipt from a
tenant"* answered the tenant register, *"log a tenant complaint"* answered the tenant register,
*"submit a purchase request"* answered **New Owner Request** (a different module and a different
reader), *"write off a bad debt"* answered the posting-map row `bad_debt_expense`, and *"close the
accounting period"* answered the **Accounting department** and the user `accounting@mall.test`.

None of that is a scoring bug. Operators say *receipt*, *complaint*, *reading*, *bad debt*; the
screens are called Payments, Requests, Utility Meters, Invoices. Reports have carried curated
`ReportCatalogue::keywords` since day one, **which is exactly why report questions were already the
most accurate tier** — they were the only tier with an operator vocabulary. Screens and create forms
had none.

`admin.assistant.synonyms` is that vocabulary, keyed by screen-guide key, in both languages,
indexed at KEYWORD weight beside the screen's own title.

**THE ONE RULE: a synonym must SELECT the screen.** Two things break it and both were shipped here
first and measured out:

* **A VERB.** `raise` under Invoices sent *"how do I raise a new invoice"* to the invoice **list**
  instead of the form. Verbs belong in `act_verbs` / `task.verbs`, where they decide the KIND of
  answer and never which screen.
* **A UBIQUITOUS NOUN.** «مستأجر» / *tenant* appears in most questions in this domain, so it
  discriminates nothing and only adds noise — listing it under Tenants sent «المتأخرات على
  المستأجرين» to the tenant register instead of the AR aging report. **Tenants has no entry at all**
  for that reason; its own name is enough.

Two structural fixes fell out of the same measurement:

* **A verb decides ORDER.** `admin.assistant.act_verbs` — *close, settle, approve, renew, terminate,
  refund, reverse, write off, reconcile…* — is deliberately separate from the creation verbs: a
  question that leads with a verb is asking how to DO something, so a screen leads and a record
  follows. Closing a period is an act and is not the making of a new record, so one list would lift
  the wrong screens. It is read in **every** supported language at once, or the same question typed
  in English on an Arabic panel would rank differently from the Arabic one.
* **A create form inherits its screen's vocabulary**, at `WEIGHT_PURPOSE` — enough to break a tie
  between two forms, never enough to put a form ahead of the register that owns the word. It has to
  be that small: at keyword weight, «تسجيل شكوى مستاجر» started answering *New request* instead of
  the requests list, and «تجديد عقد ايجار» answered *New lease*, which is the wrong act on a
  contract that already exists.

**A hyphen is a word boundary here.** `SearchText::words()` welds one — right for a search box,
where «الأهلي» is one name an operator typed — and wrong for natural language: **Month-End Close**
indexed as the single token `monthend`, so nobody typing the screen's own name in words could reach
it. `AssistantCorpus::tokenise()` splits it, on **both** sides of the fold, because folding one side
matches nothing.

After all of it: **47 of 47** operating questions — 33 English, 14 Arabic — land on a destination
their reader can act on.

**A restriction has to earn its place by measurement too.** The first attempt at the two ledger
misroutes EXCLUDED the chart of accounts from the record tier outright, on the theory that an
account name is ordinary business vocabulary. Re-measured, it fixed **nothing** — the act-verb
ordering is what fixed them — and it broke a legitimate question: *"account 51109"* could no longer
reach account 51109. It was removed and the loss is pinned by a test, because the reasoning that
produced it will look just as plausible next time.

### The gate

`AssistantVocabularyConformanceTest`. A synonym under a key that is not a real screen key is
**silently inert** — `AssistantCorpus::synonyms()` asks `trans()->has()`, gets false, returns an
empty string, and the entry reads as configuration while doing nothing. Three of the first
twenty-two were exactly that (`facility_work_orders` for `work_orders`, `deposit_transactions` for
`deposits`, `cam_pools` for `cam`), and the only symptom was a question routing badly —
indistinguishable from the vocabulary merely being incomplete. The gate also pins EN/AR key parity,
requires Arabic script in the Arabic file, and asserts the two verb lists **resolve** with
`Lang::has(..., fallback: false)` rather than merely being non-empty: a missing key returns the KEY,
which is truthy, and an editing pass that dropped `act_verbs` from the English file left this test
green while every act-ordering question regressed. Mutation-proved in all three directions.

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

## ONE surface

The floating bubble, on every admin page. The standalone `/admin/ask` screen and the
`/admin/assistant-questions` miss list were **removed** (2026-09-02): two places to ask one question
is two rankings to keep in step, and the one that drifted would be the one nobody used. The
statistics moved to a command — `php artisan atriom:assistant-report` — because a second screen
about the SOFTWARE, in a panel about a mall, is a page an operator opens once.

## The chat

It shows what the model **said**, with the sources shrunk to citations beneath it. Mounted on the
panel's `BODY_END` render hook, which is also where the module switch is consulted — **a Livewire
component has no `shouldRender()` convention**, so the gate the component used to carry was dead
code and the toggle hid nothing.

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

## It stays with you

**Open across navigation and refresh.** The component is mounted fresh by a render hook on *every*
page, so a public property is gone the moment the operator clicks a link — the chat used to close
itself on every navigation and lose the thread on every refresh. `open` and the conversation id
live in the SESSION: the smallest thing that survives both, needs no JavaScript, and is already
per-user and per-device.

**The thread is rebuilt from `assistant_questions`**, not from the session and not from a second
table. Those rows already record every turn — question, answer, reader, property, language — so a
`conversation_id` column groups them and the miss list keeps reading the same list. Two copies of
one conversation is how they come to disagree.

**Scoped by conversation AND reader.** A session id is not an identity; a shared or restored session
must never hand somebody else's questions — which can name a tenant — to the next person.

**"Clear" starts a new thread and deletes nothing.** Those rows ARE the miss list; deleting a
reader's history to tidy a panel throws away the only evidence of what the guides are missing.

**Follow-ups work.** The last three exchanges travel to the model as CONTEXT for reading the
question — never as a source of facts, which still come only from passages retrieved fresh each
turn. And when a follow-up names nothing at all — *"and how do I apply it?"*, where every word is a
stop word — retrieval is re-run with the PREVIOUS question's words attached. Without that it found
nothing, the model was never called, and the reader got "no sources" to the second half of a
question the assistant had just answered. Only when the question alone found nothing, so a
self-contained question is never polluted by what came before it.

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

## Four tiers, in order

1. **Records** — the thing the question named.
2. **Tasks** — *"New invoice"*: a link to the CREATE FORM, plus the fields it asks for.
3. **Screens and reports** — the guides and the report catalogue.
4. **Documentation** — *only when nothing EXPLAINED the question.*

**The task tier is what makes this a helper rather than a manual.** The guides say what a screen is
FOR and the handbook says how the system WORKS; neither names a field, and neither links to the
form. So *"how do I raise an invoice and what does it want from me"* was answered with a paragraph
and a link to the register — true, and two clicks short.

Fields are parsed out of each resource's own form class, so they cannot drift: adding a field to
the invoice form makes it appear on the next index. Labels come from `admin.fields.*` — the
catalogue the forms themselves label from — so the assistant says the same word the operator reads
on screen, in their language, for free.

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

## It answers with the property's own numbers (B1a)

When retrieval's top result is a catalogued report, the report is **run** and its rows become the
passage — so *"show me the trial balance"* is answered from the real ledger rather than from a
paragraph about what a trial balance is.

**The model still does not choose.** Retrieval ranked the report and the evaluation set pins that
ranking, so there is no tool-calling loop, no function-calling dialect to keep working across
providers, and nothing for the model to get wrong about which numbers to look at. It is handed the
rows and asked to read them.

**It runs as the reader**, through the same seam scheduled delivery uses: their own `canAccess()`,
then mount, parameters, `reportCsv()`. The report's query carries the property scope and the
permissions, so the figures are exactly the ones on screen — an assistant that quietly disagrees
with the AR aging page is worse than one that cannot count.

**One report per question**, at 1–35 ms and a few hundred characters. **25 rows**, and the tail is
STATED — *"showing 25 of 340"* — because "the top 25 debtors" and "your debtors" are different
claims and an invisible cut turns the first into the second.

**The model may quote those figures and may not compute with them.** No adding, averaging or
converting to a percentage: `Invoice::recomputeTotals()` is the single source of truth for what is
settled, and a model doing sums beside it is a second answer to the same question. A total the table
does not already state is answered by naming the report.

## And with the named record's own figures (B1b)

*"What does Qamaria owe?"* answers with the balance. `AssistantFields` is the allowlist — **which
fields of a record may be read back**, per model — and `RecordSummary` searches through each
resource's own `getEloquentQuery()`, so the property scope and the permissions are inherited rather
than re-implemented.

**Findable and summarisable are different questions.** All 40 models in `SearchPolicy::INDEXED`
resolve a name typed into the chat; only 8 may be quoted. Handing back the row would hand back
whatever the table carries — `password` is fillable on `User` and `Tenant`, `metadata` holds
operator-defined custom fields, a payroll run aggregates what individuals were paid. The other 32
are refused **by name with a reason**, so the decision is visible rather than an omission, and
`AssistantFieldsConformanceTest` fails on a model in neither list.

**Derived facts go through the model's own method.** A tenant's outstanding balance is
`outstandingBalance()`, never a column and never a sum — `Invoice::recomputeTotals()` already
answers that question and a second reading of it would be a second answer.

## And "how many" (B1c)

*"How many units, by status?"* → **12 units — vacant 11, occupied 1**, and in Arabic
«شاغرة: ١١، مشغولة: ١».

**Structured, never a string.** The resource comes from retrieval; the only other input is a
group-by column, and it must be one already registered in `ValueSets::forTable()`. That registry
exists because those columns have a closed set of values — using it here means a grouping can only
ever name a column somebody classified on purpose, and a question naming anything else gets a
total. There is no SQL to write and no column to invent.

**The database counts; the model reads.** The total is its own `count()` rather than a sum of the
buckets, precisely so the model is never handed a list and expected to add it up.

**The same allowlist governs counting as quoting.** Counting rows of a register nobody may quote is
a smaller leak of the same kind — *"how many employees"* is a question about people, and the screen
that answers it has its own permission.

## And comparisons, where the TOOL subtracts (B1d)

*"Compare the income statement 2025 and 2026"* runs the **same report twice** and computes each
line's change **in PHP**. The model is handed the answer, never the two figures that made it.

That is the rule enforced hardest here rather than most loosely, and deliberately: a model shown two
tables will usually say which is larger, and will eventually be confidently wrong about a number
somebody is about to act on — **a wrong delta reads as a result, not an opinion**.

- **One report against itself**, so the columns are commensurable by construction. Comparing one
  report to another is a different and much harder feature; pretending otherwise is how a chart
  subtracts square metres from money.
- **A report with no `year` parameter is refused.** Two identical runs presented as a trend is worse
  than no answer.
- **A row on one side only is NEW or GONE**, never a change from zero — *"revenue up 100%"* and
  *"this line did not exist last year"* are different statements and only one is true.
- **Both sides run as the reader**, so a comparison cannot show what a single run would not.

`PeriodCompare::diff()` is public so the arithmetic is provable against known figures without
seeding a ledger — the subtraction is the thing that has to be right.

## Judging it

**The miss list stopped being a signal, and that is measured rather than feared.** Of 45 real
questions, **zero** matched nothing: with 189 corpus entries and 1,050 documentation sections
something always matches. "Did it find anything" can no longer tell a good answer from a confident
wrong one, so `was_helpful` — 👍/👎 on each answer — is the replacement. Nullable and three-valued:
`null` is *not asked*, and treating silence as a negative would make the first useful metric a lie.

**`atriom:assistant-report`** leads with the ratings, then the misses, then spend.

**`TheAssistantAnswersTheseQuestionsTest` is the evaluation set** — 12 questions with the source each
must LEAD with, plus three that must match nothing. No model is called, so it is free and
deterministic: the wording varies, but which source a question lands on is ours and decides whether
the answer can be right at all. **When somebody reports a bad answer it becomes a row here** — that
is what turns one complaint into a permanent guard. It caught two real ranking bugs on its first
run.

**Cost is not the problem and the numbers say so:** 776 input tokens and 66 output on average, which
is ~$0.001 a question even on paid Haiku — about 1,000 questions per $1.10. Trimming prompts further
would save fractions of a cent; ranking is where the value is.

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
- **A shared VERB list must never be scored.** "create · add · new · raise · issue · open" looked
  like the obvious way to catch how people phrase a task. At keyword weight it gave all sixty-one
  task entries the same score for any question containing a common verb, so "issue a credit note"
  tied every task in the system and crowded the real answer out of five slots. The verbs are read
  only as a create-INTENT signal, which lifts tasks that already matched and cannot introduce one.
- **A one-pass regex that captures a chain eats the next match.** Reading "everything up to the next
  `::make(`" consumes the following component's class name before it stops, so the scan resumes
  mid-identifier and EVERY OTHER FIELD is silently dropped — `lease_id` and `due_date` were missing
  from the invoice form while the list still looked plausible. Two passes: find the components,
  then take each chain as the gap between them.
- **A task is not an explanation.** Letting one satisfy the "did anything answer this" test made
  "what happens when a cheque bounces" match the post-dated-cheque FORM and silence the walkthrough
  that answers it. The documentation fallback counts screens and reports only.
- **THE ANSWER CACHE MUST INCLUDE THE PROMPT AND THE MODEL.** Measured: the system prompt was
  improved, the question that motivated it re-asked, and the OLD answer came back — the model was
  never called, and the fix would not have reached anybody for the whole 168-hour TTL. A cache that
  outlives a change to its own inputs is a stale copy with a timer. Fingerprinted rather than
  hand-versioned, so editing the prompt IS the whole change.
- **Task access is asked of the RESOURCE, not the create page.** A `viewer` — who may create nothing
  anywhere — was offered "New invoice" with a link straight to the form, because the page's own
  `canAccess()` answered true and the refusal only arrived after the click. And it is checked at
  QUERY time, never while building the corpus: the corpus is memoised per locale and shared by
  every request, so filtering it by the current user would hand the next reader whatever the
  previous one was allowed to see.
- **Ask the PAGE whether it is a report, never the ranked kind.** `mergeDuplicateDestinations()`
  folds a report into its screen entry and keeps the SCREEN's identity, so a page that is both —
  most of the catalogue — arrives as kind `screen`. Checking the kind fetched figures for almost
  nothing; checking `is_a($page, DeliverableReport::class)` is the one honest question.
- **Two reports cannot run headlessly and are skipped BY NAME.** `ClauseRegister` and `ActivityLog`
  are table pages whose `$table` only initialises inside a mounted Livewire component. Catching the
  `Error` would paper over a known structural limit, which is how it stops being known.
- **The gate caught three wrong column names and three thin reasons on its first run** —
  `leases.starts_on`, `units.name` and `payments.number` do not exist (they are
  `commencement_date`, and a receipt's number is `reference`). A misspelled column reads back as an
  absent value, which is indistinguishable from a record that simply has none, so the field would
  have silently never appeared.
- **A class you name must be one you imported.** `DepositTransaction::class` in the registry
  resolved to `App\Support\DepositTransaction` — valid PHP, silently wrong — and showed up as one
  model both unclassified and stale at once.
- **The status catalogue is keyed by the MODEL, singular** — `admin.statuses.unit`, never
  `admin.statuses.units`. Getting it wrong is silent: the split rendered *"Vacant: 11"* inside an
  Arabic sentence, the half-translated shape this codebase keeps finding.
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

`AskingAtriomFindsTheScreenThatAnswersTest`, `TheAssistantReachesPastTheScreenGuidesTest`,
`TheAssistantHelpsYouDoTheThingTest`, `TheAssistantQuotesRealFiguresTest`,
`TheFloatingAssistantIsAChatTest`, `AssistantFieldsConformanceTest`,
`TheModelOnlyWordsWhatRetrievalFoundTest`, the evaluation set
(`TheAssistantAnswersTheseQuestionsTest`) and `AssistantVocabularyConformanceTest` — **118 in
all**, green together. Phase B is tested through a FAKE implementation of the contract, so the suite spends nothing;
the ceiling, the cache and the default-off were each mutation-proved. Every refusal is
paired with a control that must succeed, and four of the properties were mutation-proved: the floor,
the stop list, the locale switch, and the page's own render.

**The evaluation set is where a bad answer becomes a permanent guard.** It calls no model, so it is
free and deterministic: it pins WHICH SOURCE a question lands on, which is the half that decides
whether an answer can be right at all — good prose cannot rescue the wrong screen. The locale is
part of each case rather than scaffolding, because the corpus is built per language.

## Extension points — how to change it safely

- **A screen is found more easily → add an `admin.assistant.synonyms` entry**, in both languages,
  under the screen's own guide key. This is the intended lever and the first one to reach for. It
  must SELECT the screen: never a verb (those go in `act_verbs`), never a word that appears in most
  questions in this domain. Widening the guide's `purpose` sentence is the weaker second lever, at
  weight 3 per word.
- **An operator's verb the assistant does not know** → `admin.assistant.act_verbs`, both languages.
  It decides ORDER only — screen before record — never which screen.
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
