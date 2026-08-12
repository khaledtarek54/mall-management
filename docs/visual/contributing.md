# Adding to this handbook

<p class="eyebrow">For the team</p>

This handbook is meant to be **edited**, not admired. It's plain Markdown with a small kit of copy-paste "picture" components. If you can edit a text file, you can add to it. This page is the living style guide — every component below shows the **rendered result** and the **exact code** to copy.

## The shape

Each subsystem is a folder under `docs/visual/`, and follows the same three-part pattern:

<div class="emap"><div class="enode"><span class="name">index.md</span><span class="role">the hub — a flow diagram + the entity map + the key rules</span></div><div class="enode"><span class="name">…-lifecycle.md</span><span class="role">one page per stateful record — its state machine</span></div><div class="enode"><span class="name">…-in-the-books.md</span><span class="role">the GL events, drawn as T-account cards</span></div></div>

## Add a page in four steps

1. **Create the file** — e.g. `docs/visual/operations/meters.md`. Start with a `# Title` and a `<p class="eyebrow">…</p>` label.
2. **Add it to the menu** — open `docs/visual/.vitepress/config.mts`, add a label to the `en` and
   `ar` string maps, and add a line to the matching group in `allGroups()`:
   ```ts
   { text: t.utilityMeters, link: `${p}/operations/meters` },
   ```
3. **Write the Arabic page too** — `docs/visual/ar/operations/meters.md`, then add its link to the
   `only` list in the `ar` locale. **That list is deliberate:** the Arabic sidebar shows exactly
   what has been translated, so a menu entry never points at a page that does not exist. An
   untranslated page is a shorter menu; a listed-but-missing one is a 404 nobody who reads English
   will ever see.
4. **See it live** — run `npm run docs:dev` and open the local URL. It hot-reloads as you type.

That's it. To change the colours or fonts for the whole site, edit `docs/visual/.vitepress/theme/custom.css`.

## The rules that keep it trustworthy

<div class="rule"><span class="lbl">House rules</span><b>1. Ground every fact in code.</b> End a page with the file it came from (<code>Source of truth: app/…</code>) so a reader can verify it. <b>2. Use the real account codes</b> — they live in <code>database/seeders/ChartOfAccountsSeeder.php</code>. <b>3. Keep each component's HTML on one line</b> (no line breaks inside a <code>&lt;div class="flow"&gt;…&lt;/div&gt;</code> block) — a blank line inside raw HTML breaks the Markdown renderer. <b>4. Never hand-type a count or a list that a registry already holds</b> — use a generated component instead. Five statements in this handbook went stale before that rule existed: the settlement channels, the journalizer count twice, the module count, and a panel that had been removed.</div>

<div class="rule"><span class="lbl">RTL · use logical properties</span>The theme mirrors for Arabic with <b>no plugin</b>, because it uses <code>border-inline-start</code>, <code>margin-inline-end</code> and <code>text-align: start/end</code> rather than left/right. If you add CSS, keep to logical properties — a single <code>left</code> or <code>right</code> is what would force <code>postcss-rtlcss</code> back into the build.</div>

---

## The component kit

Copy any block below, paste it into your `.md`, and change the words.

### Labels

<p class="eyebrow">Picture 1 · a flow</p>
<p class="sub">An italic subheading that sets up the picture below it.</p>

```html
<p class="eyebrow">Picture 1 · a flow</p>
<p class="sub">An italic subheading that sets up the picture below it.</p>
```

### A flow (left-to-right steps)

<div class="flow"><div class="step"><span class="n">01</span><span class="t">First</span><span class="d">What happens here.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Next</span><span class="d">Then this.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">03</span><span class="t">End</span><span class="d">Highlight the destination with <code>step hl</code>.</span></div></div>

```html
<div class="flow"><div class="step"><span class="n">01</span><span class="t">First</span><span class="d">What happens here.</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Next</span><span class="d">Then this.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">03</span><span class="t">End</span><span class="d">Highlight the destination with the hl class.</span></div></div>
```

### A lifecycle (coloured state pills)

<div class="track"><span class="pill p-grey">Draft<small>not live</small></span><span class="conn">→</span><span class="pill p-amber">Pending<small>waiting</small></span><span class="conn">→</span><span class="pill p-green">Done<small>final</small></span></div>

```html
<div class="track"><span class="pill p-grey">Draft<small>not live</small></span><span class="conn">→</span><span class="pill p-amber">Pending<small>waiting</small></span><span class="conn">→</span><span class="pill p-green">Done<small>final</small></span></div>
```

Pill colours carry meaning — pick from: `p-grey` (neutral / draft), `p-amber` (waiting / attention), `p-teal` (in progress), `p-green` (good / done), `p-red` (problem / terminal-bad). Use `<div class="branch">` with `<div class="row">` for side-states with descriptions.

### A T-account card (a ledger entry)

<div class="tcard"><div class="cap">Event — what happened</div><p class="say">One plain sentence about the event.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Something you own</span><br><small>11101001 · Main Cashier</small></td><td class="amt dr">Dr 1,000</td></tr><tr><td class="acc"><span class="crc">Income earned</span><br><small>42101001 · Miscellaneous Income</small></td><td class="amt crc">Cr 1,000</td></tr></table></div>

```html
<div class="tcard"><div class="cap">Event — what happened</div><p class="say">One plain sentence about the event.</p><table class="t"><tr><th>Account</th><th class="cr">Dr / Cr</th></tr><tr><td class="acc"><span class="dr">Something you own</span><br><small>11101001 · Main Cashier</small></td><td class="amt dr">Dr 1,000</td></tr><tr><td class="acc"><span class="crc">Income earned</span><br><small>42101001 · Miscellaneous Income</small></td><td class="amt crc">Cr 1,000</td></tr></table></div>
```

Wrap two cards in `<div class="books">…</div>` to place them side by side. Use class `dr` (teal) for debit rows and `crc` (amber) for credit rows.

### An entity map (who links to whom)

<div class="emap"><div class="enode"><span class="name">Thing</span><span class="role">what it is, in a few words</span><span class="rels"><span class="rel">belongs to Parent</span><span class="rel has">has many Child</span></span></div></div>

```html
<div class="emap"><div class="enode"><span class="name">Thing</span><span class="role">what it is, in a few words</span><span class="rels"><span class="rel">belongs to Parent</span><span class="rel has">has many Child</span></span></div></div>
```

Use `rel` (teal) for *belongs-to* and `rel has` (amber) for *has-many*.

### Callouts

<div class="rule"><span class="lbl">Rule · the label</span>Use <code>rule</code> for a hard business rule or invariant worth boxing.</div>
<div class="plain">Use <code>plain</code> for an aside or a "the interesting thing here is…" note.</div>

```html
<div class="rule"><span class="lbl">Rule · the label</span>Use rule for a hard business rule worth boxing.</div>
<div class="plain">Use plain for an aside or a "the interesting thing is…" note.</div>
```

---

### The interactive components

Four Vue components are registered globally, so they work in any page in either language — no import needed.

```md
<PostingExplorer />                      <!-- all 24 GL sources, from the registry -->
<StateMachine workflow="work_order" />   <!-- tenant_request | work_order | purchase_request -->
<PercentageRentCalculator />
<VatRateResolver />
```

<div class="rule"><span class="lbl">Two kinds, and the difference matters</span><b>Derived</b> components (<code>PostingExplorer</code>, <code>StateMachine</code>) read <code>.vitepress/data/*.json</code>, regenerated by <code>php artisan atriom:dump-handbook-data</code> from the code's own registries — so they cannot describe a system that does not exist, and a conformance test fails the build if the dump goes stale. <b>Illustrative</b> ones (the calculators) mirror a single line of arithmetic and name the class that is the authority.<br><br><b>Do not build anything in between.</b> A component that half-reimplemented a service would be a second opinion about the same money — which is exactly why the Dr/Cr lines per journalizer are NOT dumped: they resolve through the charge-code table at runtime, so any map of them would be a guess rendered as a diagram.</div>

<div class="plain">That's the whole kit — six drawing components and four interactive ones cover every page in this handbook. When you add a subsystem, copy an existing one (say <code>docs/visual/leasing/</code>) as a skeleton and swap the content. Everything themes itself for light and dark automatically, and mirrors itself for Arabic.</div>
