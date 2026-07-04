# Life of a credit note

<p class="eyebrow">A lifecycle</p>

A credit note is how you **reduce what a tenant owes** without taking money in — a refund, a goodwill gesture, or correcting an over-charge. It's the one money record that runs *backwards* along the spine.

## Its stages

<p class="sub">It's created, issued, and then applied against one or more bills.</p>

<div class="track"><span class="pill p-grey">Draft<small>being prepared</small></span><span class="conn">→</span><span class="pill p-amber">Issued<small>live · not yet used</small></span><span class="conn">→</span><span class="pill p-teal">Partly&nbsp;applied<small>some used up</small></span><span class="conn">→</span><span class="pill p-green">Applied<small>fully used</small></span></div>

<div class="branch"><div class="row"><span class="pill p-red">Void</span><span>Cancelled before it was used up. A void note reverses out of the books and can no longer settle any bill — Atriom re-checks this under a lock, so a note that was applied a moment ago can't be voided out from under a live bill.</span></div></div>

## How it settles a bill

Applying a credit note works exactly like a payment: it lowers a bill's **balance**. That's why a bill can reach **Paid** (or the [Credited](/money/invoice-lifecycle) state) without any cash arriving. A partly-applied note keeps a **remaining balance** of its own, ready to use against the next bill.

<div class="plain">A credit note is deliberately kept <b>separate</b> from a payment: money-in and value-returned are different events, and the books record them differently — a payment brings cash in, a credit note reverses revenue back out.</div>

## What it does in the books

A credit note **reverses** part of a sale: it debits *Sales Returns & Allowances*, gives back the *VAT* that was charged, and reduces the tenant's *receivable*. A **Draft** or **Void** note posts nothing — only **Issued** and **Applied** notes hit the ledger.

See the exact entry on **[What happens in the books →](/money/the-books)**

_Source of truth: `app/Services/CreditNoteService.php` and `docs/modules/07-credit-notes.md`._
