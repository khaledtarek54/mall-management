# 39 · Ask Atriom — the in-app assistant (A0 · A1 · A2)

**Status: A0, A1 and A2 shipped.** No language model is involved. See
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
| `AnswerQuestionService` | `app/Services/Assistant` — fold, score, filter by access, record |
| `Assistant` (page) | `/admin/ask` |

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

`AskingAtriomFindsTheScreenThatAnswersTest` (18 cases) and
`TheAssistantReachesPastTheScreenGuidesTest` (9). Every refusal is
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
