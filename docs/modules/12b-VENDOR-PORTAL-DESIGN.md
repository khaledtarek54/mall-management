# Vendor portal — design, before any code

> **Status: DESIGN. Nothing here is built.** Written first, at the operator's instruction, and kept
> deliberately small. Read [docs/benchmarks/fm/02-servicechannel-contractor-loop.md](../benchmarks/fm/02-servicechannel-contractor-loop.md)
> §1 and §4 first — this is that loop, minus everything a single-operator mall does not need.

---

## 1. What it is for, in one paragraph

Eltizam executes through contractors it does not employ. Today every step of that relationship is an
**internal column change**: a coordinator dispatches by setting `vendor_id`, records the contractor's
quote on their behalf, marks the job accepted, and types in what they were told on the phone. The
contractor has no account, sees nothing, and confirms nothing. The portal gives them the four
actions that are genuinely theirs — **accept, quote, update, evidence** — and nothing else.

## 2. The one design rule

> **A contractor may only ever see or touch a job that has been dispatched to them.**

Not their vendor record, not the property, not other contractors' work, not costs that are not
theirs. Everything below is a consequence of that rule, and it is the rule the whole security model
is tested against.

## 3. What the contractor can do — four verbs, and nothing else

| Verb | What it is | Already exists |
|---|---|---|
| **Accept** | Acknowledge a dispatched job. **This starts the SLA resolution clock** (FR-CM-07 — the clock starts at acceptance, so an engineer is not charged for queue time) | `FacilityWorkOrder::acknowledged_at` + the response-breach scan |
| **Quote** | Submit a proposal when the work will exceed the NTE | `WorkOrderProposal` + `WorkOrderProposalService` — built in close-out step 6, currently entered *by the operator on the contractor's behalf* |
| **Update** | Add a note to the job's thread | The work order has no comment thread; `TenantRequest` does. See §7 |
| **Evidence** | Attach photographs of the finished work | The `evidence` media collection, built 2026-08-19 |

**Three of the four already exist as services.** The portal is mostly a *surface*, which is why it is
worth doing and why it should stay small.

### Explicitly NOT in scope

- **Marking a job done.** ServiceChannel lets a provider check out; here `facility.complete` runs a
  checklist gate, an evidence gate and the cost object. A contractor saying "finished" is a
  *claim*; the operator's completion is a *decision*. Keeping them apart is the same reasoning that
  made the tenant's confirmation a control rather than a courtesy — the party paid for the work
  should not be the one who closes it.
- **Invoicing.** A contractor's bill is an accounting document with a tax code, a posting date and a
  GL consequence. It stays in the operator's hands.
- **Browsing anything.** No vendor directory, no property pages, no reports. The portal is a list of
  *your* jobs and the four verbs.

## 4. Authentication — reuse, do not invent

`VendorContact` already exists (name, email, phone, per vendor). It gains what a login needs:
`password`, `is_portal_user`, `last_login_at`, and the standard reset flow.

**Modelled on the tenant portal, which solved this exact problem**: a company (`Vendor`) with several
people (`VendorContact`), its own guard, its own panel at `/vendor`, and every query scoped to the
company. That is `TenantUser` → `Tenant` → `/portal` with the names changed, and reusing the shape
means reusing its tests, its scoping and its failure modes rather than discovering them again.

**One deliberate difference:** the tenant portal distinguishes `is_admin` (may write) from read-only
staff. A contractor's contacts are few and all of them act, so **every portal contact may act** —
until an operator asks otherwise. Fewer states, fewer bugs.

## 5. Scoping — where the one rule is enforced

Three layers, because the first two are UI and only the third is a gate:

1. **The panel's queries** filter to `vendor_id = the signed-in contact's vendor`, and to jobs whose
   status has actually been dispatched (`open`/`in_progress`), never a draft.
2. **Every action re-checks ownership server-side**, exactly as `abort_unless` does in the admin
   panel. A narrowed list is not a gate; the Livewire payload still carries an id.
3. **A foreign id 404s, never 403s** — the `/api/v1` rule. A 403 confirms the job exists.

`docs/PROPERTY-ISOLATION.md` does not apply: a contractor is not scoped to a property, they are
scoped to *their dispatches*, which may span malls.

## 6. What the operator sees change

- Dispatching a job **notifies the contractor** (their contacts), where today it changes a column
  silently.
- `acknowledged_at` starts being set by the contractor rather than by staff, which makes the response
  SLA a measurement rather than an estimate. **This is the single biggest change in data quality**
  and it costs nothing new to build.
- A quote arrives as a `WorkOrderProposal` the operator approves or refuses — the same service, the
  same ladder, the same refusals. Only the author changes.

## 7. The one thing that does not exist yet

**A work order has no comment thread.** `TenantRequest` has `TenantRequestComment` with an
`is_internal` flag; the work order has `notes`, a single field. "Update" needs a thread, and it needs
the same internal/external split so the operator can write something the contractor must not read.

That is the only new domain object the portal requires. Everything else is a screen over something
already built — which is the argument for doing it in this order.

## 8. Build order, if approved

| # | Step | Why here |
|---|---|---|
| 1 | Work-order comment thread (internal/external), admin side only | The one missing primitive. Useful on its own even if the portal never ships |
| 2 | `VendorContact` login + `/vendor` panel + the scoping rule, with its refusal tests | The security model, proven before any feature hangs off it |
| 3 | The jobs list + **accept** | The highest-value verb: it makes the response SLA real |
| 4 | **Evidence** + **update** | Both are surfaces over what exists |
| 5 | **Quote** | Reuses `WorkOrderProposalService` unchanged; only the author differs |
| 6 | Dispatch notification to the contractor | Closes the loop |

**Steps 1–3 are the useful minimum.** If the portal stopped there it would still have converted
dispatch from an internal column change into an agreement with a timestamp, which is the substance
of the ServiceChannel loop. 4–6 are worth doing and are not the point.

## 9. What would make this a bad idea

Stated so the decision can be re-taken honestly:

- **Contractors who will not log in.** A portal nobody uses makes the SLA *worse*, because
  `acknowledged_at` stops being filled by staff and starts being filled by nobody. **Mitigation:**
  the operator must retain the ability to accept on a contractor's behalf, and step 3 must keep the
  admin-side action rather than replacing it.
- **A second place to be wrong.** Two ways to accept a job means two code paths; both must go through
  `FacilityWorkOrderService::transition()`, never touch the column directly.
- **Scope creep into a marketplace.** ServiceChannel monetises a contractor network. Eltizam has its
  own vendor list, and building a marketplace for one operator is building an empty room —
  already recorded in `benchmarks/fm/02` §8 under "what not to copy".
