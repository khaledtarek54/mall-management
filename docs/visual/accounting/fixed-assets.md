# Fixed assets &amp; depreciation

<p class="eyebrow">A lifecycle + the books</p>

A fixed asset — a generator, a chiller, mall furniture — isn't an expense the day you buy it. It's an asset you **capitalise**, then **wear down** a little each month (depreciation) over its useful life, until you eventually **dispose** of it.

## An asset's life

<div class="track"><span class="pill p-green">Active<small>in use · depreciating</small></span><span class="conn">→</span><span class="pill p-grey">Disposed<small>sold or written off</small></span></div>

<div class="plain">A disposed asset <b>stays on the books</b> — its history is preserved. Depreciation is <b>straight-line</b>: the same charge every month = (cost − salvage) ÷ useful-life-months, and accumulated depreciation is always the <b>sum</b> of those charges, never a stored number.</div>

## Buy it, then wear it down

<p class="sub">A 12,000 asset over 60 months → 200 of depreciation a month.</p>

<div class="books"><div class="tcard"><div class="cap">Acquisition — capitalise it</div><p class="say">Cash becomes an asset on the balance sheet — not a cost.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">The asset</span><br><small>12101001 · Furniture &amp; Equipment</small></td><td class="amt dr">Dr 12,000</td></tr><tr><td class="acc"><span class="crc">Cash out</span><br><small>11102001 · Bank Account</small></td><td class="amt crc">Cr 12,000</td></tr></table></div><div class="tcard"><div class="cap">Depreciation — one month's wear</div><p class="say">A slice of the cost becomes an expense; the asset's value quietly drops.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">This month's wear</span><br><small>51107001 · Depreciation Expense</small></td><td class="amt dr">Dr&nbsp;&nbsp;&nbsp;200</td></tr><tr><td class="acc"><span class="crc">Value used up (so far)</span><br><small>12201001 · Accumulated Depreciation</small></td><td class="amt crc">Cr&nbsp;&nbsp;&nbsp;200</td></tr></table></div></div>

## Sell it — and settle the gain or loss

<p class="sub">After 40 months, accumulated is 8,000, so book value is 4,000. You sell it for 5,000 — a 1,000 gain.</p>

<div class="tcard"><div class="cap">Disposal — off the books</div><p class="say">Strip out the original cost and its accumulated wear, take the cash, and recognise the difference.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Wear reversed out</span><br><small>12201001 · Accumulated Depreciation</small></td><td class="amt dr">Dr&nbsp;&nbsp;8,000</td></tr><tr><td class="acc"><span class="dr">Sale proceeds</span><br><small>11102001 · Bank Account</small></td><td class="amt dr">Dr&nbsp;&nbsp;5,000</td></tr><tr><td class="acc"><span class="crc">Original cost removed</span><br><small>12101001 · Furniture &amp; Equipment</small></td><td class="amt crc">Cr 12,000</td></tr><tr><td class="acc"><span class="crc">Profit on the sale</span><br><small>42102001 · Gain on Disposal</small></td><td class="amt crc">Cr&nbsp;&nbsp;1,000</td></tr></table></div>

<div class="rule"><span class="lbl">Rule · gain or loss = proceeds − book value</span>Book value (NBV) = cost − accumulated depreciation = 12,000 − 8,000 = <b>4,000</b>. Sell above it → a <b>gain</b> (42102001); sell below → a <b>loss</b> (52102001). The entry nets Furniture and Accumulated Depreciation back to zero for that asset, so it's fully off the books. Depreciation and disposal touch <em>neither</em> receivables nor payables — they can never break the AR/AP tie-out.</div>

_Source of truth: `app/Services/DepreciationService.php`, `app/Services/Accounting/Journalizers/{FixedAssetAcquisition,DepreciationEntry,FixedAssetDisposal}Journalizer.php`, and `docs/modules/23-fixed-assets.md`._
