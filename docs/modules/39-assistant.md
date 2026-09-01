# 39 · Ask Atriom — the in-app assistant (A0)

**Status: A0 shipped.** No language model is involved. See
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
| `AnswerQuestionService` | `app/Services/Assistant` — fold, score, filter by access, record |
| `Assistant` (page) | `/admin/ask` |

**The corpus is derived, never listed.** `ScreenGuides::SCREENS` and `ReportCatalogue::REPORTS` are
the sources, and both already have conformance gates forcing completeness — so a new screen becomes
searchable the day somebody writes its guide, with no second registry to forget.

## Business rules & invariants

- **It can never name a screen its reader may not open.** Every candidate is filtered through that
  screen's own `canAccess()` inside the service. Two roles asking one question get different
  answers, correctly — a link that 403s reads as a broken system rather than as a boundary.
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

`tests/Feature/Scenarios/AskingAtriomFindsTheScreenThatAnswersTest.php` — 14 cases. Every refusal is
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
- **Do not add a second corpus.** If the guides are thin, the fix is the guides.
