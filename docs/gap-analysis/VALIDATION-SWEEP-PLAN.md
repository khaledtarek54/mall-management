# Validation sweep — spacing · leasing · receivables · payables

**Status:** planned, not started. Written 2026-08-11.

The goal is not "add more validation". It is to know, for every rule that matters in these four
areas, **where it is enforced and whether anything proves it** — and to move the ones that are only
enforced in a form, because a form is the one layer every other writer bypasses.

---

## 1. The four layers, and what belongs at each

A rule enforced at the wrong layer looks correct and is not. This is the ladder, strongest first:

| Layer | Enforces | Use when | Bypassed by |
|---|---|---|---|
| **0 — DB constraint** | unconditional uniqueness, NOT NULL, FK | the rule is absolute and stateless | nothing |
| **1 — Model hook** | invariants true for *every* writer | any write must obey it, from any path | nothing in-process |
| **2 — Service** | rules needing a lock or a transaction | check-then-act, or several rows must move together | a direct model write |
| **3 — Form** | inline errors, filtered pickers | making the rule *visible* before submit | the API, console, imports, any service |

**The rule of thumb this codebase already follows:** a business rule lives at layer 2 and is
*mirrored* at layer 3 for UX. `Unit::isActivelyLeased()` is the exemplar — one predicate, called by
`LeaseCreationService` under `lockForUpdate` (authoritative) and by `LeaseForm` (inline error). The
form is never the only guard.

**The trap to hunt for:** a rule that exists ONLY at layer 3. It passes every manual test, and the
mobile API or an import walks straight through it. Module 03's close-out found exactly this twice —
a portal form scoped its `<Select>` options but never clamped the payload.

---

## 2. What the sweep produces

1. **A rule matrix** per area: rule → layer(s) it is enforced at → the test that proves it.
2. **A test for every rule that has none**, written as an *exploit first*: make the bad write
   succeed, then add the guard and watch it fail. A guard nobody has seen fail is a guard nobody
   knows works.
3. **A promotion list**: rules currently at layer 3 only, moved to layer 1 or 2.
4. **A conformance gate** where a class of rule can be checked mechanically (the way
   `UniqueRuleScopeConformanceTest` already checks that every Filament `unique()` is property-scoped).

---

## 3. Where to look, by area

Not a guess-list — these are the rules the modules already claim, to be verified one at a time.

### Spacing (properties, units, floors, rentable items, areas)
- Unit code unique **per property**, not globally (DB: `['asset_id','code']` — 7 tables use this shape).
- A unit cannot be under two active leases — layer 2 + 3 today, **no DB constraint is possible**
  (it is conditional on status and dates), so the lock is the guard. Verify the lock, not the check.
- Floor level and code unique per property; a unit's floor must belong to the unit's property.
- A rentable item cannot be held by two leases on one day (DB: `['lease_id','rentable_item_id','effective_from']`
  — note this does NOT prevent overlapping *ranges*, only identical starts; verify the service).
- Area (m²) must be positive; a remeasurement must not overlap the row it closes.
- **Cross-property:** every `asset_id` a form can set must pass `assertAssetInScope`.

### Leasing (leases, charges, options, events)
- Commencement before expiry; rent-commencement not before commencement.
- Charge schedule rows of one type must not overlap (the overlap guard) and should not gap.
- Escalation collar: floor ≤ ceiling (layer 1 today).
- A terminal lease is immutable (layer 1).
- Percentage-rent tiers must not overlap; breakpoint positive.
- Deposit amount non-negative; option notice window ordered.
- **Reachability check:** does each of these refuse an API/import write, or only a form submit?

### Receivables (invoices, payments, credit notes, deposits, PDCs)
- An invoice's items must sum to its subtotal; VAT per line consistent with the charge type.
- No settlement channel may over-settle — **already proven to compose** (`FourSettlementChannelsComposeTest`).
- A payment cannot allocate to another tenant's invoice, or to one in another property.
- A credit note cannot exceed the invoice it corrects.
- Posting date must be in an open period (registry: `PostingDateGuards`).
- A PDC cannot clear against a cancelled invoice; cheque number unique per bank per tenant?  ← verify
- **Money records are never deletable** (registry: `DeletionPolicy`) — already gated.

### Payables (vendor bills, payments, expenses, purchase requests)
- A bill cannot be paid more than its net of withholding tax.
- A bill's property must match its purchase request's property (fixed in module 29 — verify it holds).
- Vendor invoice number unique per vendor  ← verify whether this exists at all.
- Approval bands enforced server-side, not only in the form (registry: `ApprovalRule`).
- Posting date guard on **both** the approve and the pay path (this was the module 12 finding).

---

## 4. Method, per rule

1. **State the rule in one sentence**, as an operator would.
2. **Find every writer** — form, service, API resource, console command, importer, seeder.
3. **Exploit it**: write the test that makes the bad state, using a *reachable* input (a fixture that
   sets a column no form writes proves nothing — see `feedback_tests_must_use_reachable_inputs`).
4. If it succeeds, **fix at the right layer** and watch the test flip.
5. If it fails, **keep the test** — the guard now has a witness.
6. **Pair every refusal with a control** that must succeed, or the test passes when the whole path is
   a no-op.

---

## 5. Sequencing

Receivables and payables first: a wrong number there is money, and money is the thing that cannot be
quietly corrected later. Then leasing (it generates the money), then spacing (it is mostly
uniqueness, and 81 DB constraints already carry a lot of it).

Estimate: one focused session per area. This is not a refactor — most of the work is reading and
writing tests, and the expected outcome is that **most rules are already enforced correctly**, with a
handful sitting at layer 3 that need promoting. Say so honestly when that is what is found, rather
than manufacturing findings to justify the sweep.
