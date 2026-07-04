# Inventory in the books

<p class="eyebrow">The stock ledger, drawn</p>

Spare parts, filters, cleaning supplies — inventory is the stock your team draws on to keep the mall running. Atriom tracks it as an **append-only ledger**: you never edit a count, you only add movements, and on-hand is always the **sum** of them.

## Four kinds of movement

<div class="branch" style="border-top:none;padding-top:4px;"><div class="row"><span class="pill p-green">Receipt</span><span>Stock arrives (a purchase) — <b>adds</b> to on-hand.</span></div><div class="row"><span class="pill p-red">Consumption</span><span>Stock is used (e.g. a part fitted on a repair) — <b>removes</b> from on-hand.</span></div><div class="row"><span class="pill p-amber">Adjustment</span><span>A stock-take correction — a signed nudge up (found) or down (shrinkage).</span></div><div class="row"><span class="pill p-grey">Transfer</span><span>Moved between warehouses — nets to zero, <b>no</b> ledger effect.</span></div></div>

<div class="rule"><span class="lbl">Invariant · on-hand is derived, never stored</span>There is no "quantity on hand" field to get out of sync — on-hand is <b>SUM(signed movements)</b>, recomputed every time. Corrections are a <em>new</em> adjustment movement, not an edit. And consumption can never drive stock negative: it locks the item and re-checks availability inside the transaction, so two tickets racing for the last part can't both win.</div>

## What each movement posts

<p class="sub">Receiving 100 units at 50 each (5,000), then a repair consumes 10 (500).</p>

<div class="books"><div class="tcard"><div class="cap">Receipt — stock comes in</div><p class="say">Value moves into inventory; the "not yet invoiced" side is parked in a clearing account.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Stock on hand</span><br><small>11301001 · Inventory / Stock</small></td><td class="amt dr">Dr 5,000</td></tr><tr><td class="acc"><span class="crc">Owed, bill not in yet</span><br><small>21701001 · Goods Received Not Invoiced</small></td><td class="amt crc">Cr 5,000</td></tr></table></div><div class="tcard"><div class="cap">Consumption — a part is used</div><p class="say">Stock leaves inventory and becomes a maintenance cost.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Maintenance cost</span><br><small>51102001 · Repairs &amp; Maintenance</small></td><td class="amt dr">Dr&nbsp;&nbsp;&nbsp;500</td></tr><tr><td class="acc"><span class="crc">Stock on hand</span><br><small>11301001 · Inventory / Stock</small></td><td class="amt crc">Cr&nbsp;&nbsp;&nbsp;500</td></tr></table></div></div>

<div class="plain"><b>GRNI</b> (Goods Received Not Invoiced) is the clever bit: when stock arrives before the supplier's bill does, its value sits in this clearing liability — <em>not</em> Accounts Payable — so your AP always ties out to real vendor bills. When the bill lands, it clears GRNI.</div>

## A stock-take correction

<p class="sub">Two units come up missing at a count (shrinkage) — 100 written off.</p>

<div class="tcard"><div class="cap">Adjustment — shrinkage</div><p class="say">The loss lands in its own expense line, so real usage stays clean.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Stock loss</span><br><small>51108001 · Inventory Adjustment</small></td><td class="amt dr">Dr&nbsp;&nbsp;&nbsp;100</td></tr><tr><td class="acc"><span class="crc">Stock on hand</span><br><small>11301001 · Inventory / Stock</small></td><td class="amt crc">Cr&nbsp;&nbsp;&nbsp;100</td></tr></table></div>

<div class="legend"><span>Found stock reverses this — <b class="dr">Dr</b> Inventory / <b class="crc">Cr</b> Inventory Adjustment.</span></div>

_Source of truth: `app/Services/StockMovementService.php`, `app/Services/Accounting/Journalizers/InventoryMovementJournalizer.php`, and `docs/modules/22-inventory.md`._
