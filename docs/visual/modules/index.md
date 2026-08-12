# Every module

<p class="eyebrow">The reference</p>

Atriom is thirty-six modules. The pages before this one draw how the money moves; this one is the
**reference** — what each module is, what it changes elsewhere, and what it puts in the books.

<div class="plain">Every screen in the system also explains <b>itself</b>. Open any list and press <b>How this works</b> — the same four questions answered there are the ones summarised here, in whichever language you are signed in with.</div>

## What reaches the general ledger

Twenty-four kinds of document post to the books. This table is generated from the code's own
registry — the same one the real-time hooks, the nightly sweep, the close gate and the drift check
all read — so it cannot list something the system does not post, or miss something it does.

<PostingExplorer />

<div class="rule">
<span class="lbl">How to read it</span>

**Entry dated by** is the column that decides which period an entry lands in. **Closed-period guard**
is the service that refuses a document dated into a closed month — where it says *system-dated*, the
date is never typed by an operator, so there is nothing to guard. **Edits to a posted entry** counts
each column by what changing it does: *refused* outright, *re-derived* (the entry is voided and
re-posted), *future only*, *text only*, or no effect. **Deletion** is the tier — hover a
never-deletable one to see the workflow that corrects it instead.
</div>

## The flows the services enforce

A status with nothing after it is an **end**. That is the fact operators most often want: a closed
request cannot be re-opened, and no amount of clicking will find a path out of it.

### A tenant request

<StateMachine workflow="tenant_request" />

### A facility work order

<StateMachine workflow="work_order" />

### A purchase request

<StateMachine workflow="purchase_request" />

## Two rules worth playing with

The rest of this handbook explains rules. These two are the ones people most reliably get backwards,
so they are worth changing a number in rather than reading about.

### Percentage rent: natural or artificial

<PercentageRentCalculator />

### Why a back-dated invoice does not bill today's VAT rate

A rate is a **dated rung**, never a column. Move the document date across a rise and watch the
answer stay behind it — that is what lets an increase be entered months in advance, and what keeps a
correction to last quarter on last quarter's rate.

<VatRateResolver />

---

_The deep reference for every module is `docs/modules/NN-*.md` in the repository — worked numbers,
edge cases and the reasoning behind each decision. This layer draws the concepts on top of them._
