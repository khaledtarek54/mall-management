# The ledger &amp; the rules

<p class="eyebrow">A lifecycle + the laws</p>

The general ledger is the mall's official record of truth. To keep it trustworthy, entries follow a strict lifecycle and a handful of iron rules that can't be bent — not by a user, not by a bug.

## A journal entry's life

<div class="track"><span class="pill p-grey">Draft<small>being built</small></span><span class="conn">→</span><span class="pill p-green">Posted<small>live on the books</small></span><span class="conn">→</span><span class="pill p-red">Void<small>reversed</small></span></div>

<div class="rule"><span class="lbl">Rule · a posted entry is never edited — only voided</span>Once an entry is <b>Posted</b>, it's immutable. To undo it you don't delete it — you <b>void</b> it, which posts a mirror-image <b>reversing entry</b> (debits and credits swapped). <em>Both</em> the original and the reversal stay on the books and net to zero, so there's a permanent, honest audit trail. Reports count both — dropping the void would leave its reversal as a phantom balance.</div>

## The period gate

<p class="sub">Each calendar month is a period that can be locked once its figures are final.</p>

<div class="track"><span class="pill p-green">Open<small>accepts entries</small></span><span class="conn">→</span><span class="pill p-grey">Closed<small>final · locked</small></span></div>

<div class="plain">A <b>Closed</b> period rejects any attempt to post — or to reverse — an entry dated inside it. That's what makes a reported month final: once accounting closes December, December's numbers can't move. If a genuine correction is needed, an admin <b>reopens</b> the period first. (This month-locking is separate from the year-end <em>closing entry</em> — see <a href="/accounting/close-and-reconcile">Close &amp; reconcile</a>.)</div>

## The iron laws of every entry

<div class="rule"><span class="lbl">Invariant · the four rules the posting engine enforces</span><b>1. Debits = credits.</b> Every entry must balance to the penny (Σ debit = Σ credit) and move a non-zero amount. <b>2. At least two lines</b>, each one-sided — a line is either a debit or a credit, never both, never negative. <b>3. Postable leaves only</b> — a summary/parent account is rejected. <b>4. An open period</b> — the entry's date must land in a period that's still open. Break any one and the post is refused; there's no way to write an unbalanced or out-of-period entry.</div>

<div class="plain">Because these hold on every single post, the whole ledger is <b>guaranteed to balance at all times</b> — which is why the trial balance always ties, and why the books can be trusted without re-checking them by hand.</div>

_Source of truth: `app/Services/Accounting/JournalPostingService.php`, `app/Services/Accounting/PeriodService.php`, and `docs/modules/21-general-ledger.md`._
