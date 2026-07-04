# Life of an invoice

<p class="eyebrow">A lifecycle</p>

A bill isn't just "paid" or "unpaid" — it moves through defined stages, and different stages mean different things for the books and for chasing money. This is every stage it can be in.

## The happy path

<p class="sub">Left to right is the normal life of a bill that gets paid.</p>

<div class="track"><span class="pill p-grey">Draft<small>being prepared</small></span><span class="conn">→</span><span class="pill p-amber">Issued<small>sent · awaiting pay</small></span><span class="conn">→</span><span class="pill p-teal">Partly&nbsp;paid<small>some money in</small></span><span class="conn">→</span><span class="pill p-green">Paid<small>balance is zero</small></span></div>

What pushes a bill forward is a **payment** or an **applied credit note** — both lower the balance. The instant the balance hits zero, Atriom flips it to **Paid** by itself (that's the [recomputeTotals rule](/money/#the-one-rule-that-keeps-money-honest)).

## The other states it can reach

<div class="branch"><div class="row"><span class="pill p-red">Overdue</span><span>An <b>issued</b> or <b>partly-paid</b> bill whose due date has passed while money is still owed. This is what late-fee and overdue scans act on.</span></div><div class="row"><span class="pill p-grey">Cancelled</span><span>Raised in error and pulled off the books. Its balance is forced to zero, and any credit it had consumed is handed back to the tenant.</span></div><div class="row"><span class="pill p-teal">Credited</span><span>Settled in full by a credit note rather than cash. It <b>stays on the books</b> — the revenue was still earned — it just wasn't paid with money.</span></div><div class="row"><span class="pill p-amber">Disputed</span><span>A manual hold: the tenant is contesting it. Atriom stops auto-changing its status until someone resolves it.</span></div></div>

## What each stage means for the books

<div class="plain">Only some stages touch the ledger. A <b>Draft</b> and a <b>Cancelled</b> bill post <b>nothing</b> — revenue is recognised the moment a bill is <b>Issued</b>, not before. Every other stage (issued, partly-paid, paid, overdue, disputed, credited) keeps its receivable-and-revenue entry standing. See exactly what that entry is on <a href="/money/the-books">What happens in the books →</a></div>

_Source of truth for the rules: `app/Models/Invoice.php` (`recomputeTotals`) and `docs/modules/05-billing-invoices.md`._
