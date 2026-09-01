# Vendor portal — design, before any code

> **Status: BUILT (2026-08-28) — all six steps of §8. The loop is closed.** Written first, at the operator's instruction, and kept
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
`password`, `is_portal_user`, `last_login_at`, and the standard reset flow. ✅ Built 2026-08-28.

**`last_login_at` is wired, not just added** (`StampVendorLastLogin`) — and that was caught in
review, not by a gate: the column was created, cast and written by nothing, the same inert shape as
`tenants.locale`. It matters precisely because of §9: if contractors will not log in, this is how an
operator finds out before the SLA figures quietly become fiction.

**Its own reset-token table**, not the tenant one. A reset table is keyed by email and one person can
be both a retailer's staff member and a contractor's contact — a building manager. Sharing a table
lets one reset consume the other's token, and the symptom is "the link says invalid" for a reason
nobody can see. `->authPasswordBroker('vendor_contacts')` on the panel is load-bearing: without it
Filament resolves the default `users` broker and the reset runs against the ADMIN table — exactly
what the tenant portal shipped with.

**Panel access gates on the login AND the company**, and on `status === active` rather than
`isDispatchable()`. Dispatchability goes false the day an insurance certificate lapses, and a
contractor whose COI expired mid-job must still read the thread and hand over evidence: suspending an
account and suspending dispatches are different decisions.

**Modelled on the tenant portal, which solved this exact problem**: a company (`Vendor`) with several
people (`VendorContact`), its own guard, its own panel at `/vendor`, and every query scoped to the
company. That is `TenantUser` → `Tenant` → `/portal` with the names changed, and reusing the shape
means reusing its tests, its scoping and its failure modes rather than discovering them again.

**One deliberate difference:** the tenant portal distinguishes `is_admin` (may write) from read-only
staff. A contractor's contacts are few and all of them act, so **every portal contact may act** —
until an operator asks otherwise. Fewer states, fewer bugs.

## 5. Scoping — where the one rule is enforced

Three layers, because the first two are UI and only the third is a gate:

1. **The panel's queries** filter to `vendor_id = the signed-in contact's vendor`.
   *(Corrected on build, 2026-08-28: this line also asked for "never a draft" — and
   `FacilityWorkOrder::STATUSES` has no pre-dispatch state to exclude. `VendorScope::VISIBLE_STATUSES`
   exists as an allowlist that today equals every status, so it narrows nothing yet; its value is
   that a status added later is invisible to contractors until someone adds it deliberately. Stated
   rather than implied, because a security class claiming a protection it does not have is worse than
   one that claims less.)*
   With nobody signed in the scope matches **nothing**, never everything — a scope that widens when
   the guard is empty is how a portal leaks its whole table to an unauthenticated request.
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

**A work order had no comment thread** — ✅ **built 2026-08-28.** `TenantRequest` has
`TenantRequestComment` with an `is_internal` flag; the work order had `notes`, a single field. "Update" needs a thread, and it needs
the same internal/external split so the operator can write something the contractor must not read.

That is the only new domain object the portal requires. Everything else is a screen over something
already built — which is the argument for doing it in this order.

## 8. Build order, if approved

| # | Step | Why here |
|---|---|---|
| 1 | ~~Work-order comment thread (internal/external), admin side only~~ ✅ **DONE 2026-08-28** — `facility_work_order_comments`, `FacilityWorkOrderComment`, `CommentOnWorkOrderService`, `WorkOrderCommentsRelationManager`, `AWorkOrderHasAThreadTest` | The one missing primitive. Useful on its own even if the portal never ships — and it is now in use on the admin side whether or not the portal follows |
| 2 | ~~`VendorContact` login + `/vendor` panel + the scoping rule, with its refusal tests~~ ✅ **DONE 2026-08-28** — `vendor` guard + `vendor_contacts` provider + its OWN reset-token table, `VendorPanelProvider`, `App\Support\Filament\VendorScope`, `AContractorSeesOnlyTheirOwnJobsTest` (mutation-proved: dropping the vendor filter turns 2 red, gutting the ownership gate turns 1 red) | The security model, proven before any feature hangs off it |
| 3 | ~~The jobs list + **accept**~~ ✅ **DONE 2026-08-28** — `WorkOrderResource` (list only; no create/edit/delete) + `AcceptWorkOrderService`, idempotent and lock-safe. **The admin-side accept was ADDED, not replaced** — §9's mitigation, and both sides call the one service | The highest-value verb: it makes the response SLA real |
| 4 | ~~**Evidence** + **update**~~ ✅ **DONE 2026-08-28** — the `evidence` collection, append-only via `App\Filament\Actions\EvidenceUpload` (never replace: the completion gate reads it, and a replace would erase what an earlier decision rested on) and the step-1 thread, contractor-side and **always public** | Both are surfaces over what exists |

> **⚠️ Append-only is `EvidenceUpload`, and it was NOT `->appendFiles()` (fixed 2026-09-01).** Both
> doors onto this collection — the contractor's here and the operator's `attachEvidence` on the
> admin work-order table — shipped carrying `->appendFiles()` and a comment promising exactly the
> behaviour they did not have. That option governs the **browser widget**; the save runs
> `SpatieMediaLibraryFileUpload::deleteAbandonedFiles()`, which deletes every medium whose uuid is
> absent from the component's state — and an **action modal's** state is never hydrated from the
> record, so every photograph already on the job was abandoned by definition. Measured: a contractor
> uploaded `before.jpg`, returned the next day with `after.jpg`, and the collection held `after.jpg`
> alone. The two doors also erased each other. `EvidenceUpload` is the single definition and saves
> without the delete — correct for a collection that may only grow, since **removing evidence is not
> one of the verbs on either side**. (`EvidenceAppendsAndNeverReplacesTest`, mutation-proved on both
> doors.)
| 5 | ~~**Quote**~~ ✅ **DONE 2026-08-28** — and *"only the author differs"* was truer than this row knew: `submitted_by_user_id` is a FK to `users` and means *the operator who keyed it*, so a `VendorContact` there would name a random admin. `submitted_by_vendor_contact_id` sits beside it | Reuses `WorkOrderProposalService`; the approval ladder, the NTE rise and every refusal are unchanged |
| 6 | ~~Dispatch notification to the contractor~~ ✅ **DONE 2026-08-28** — `WorkOrderDispatchedNotification` to every PORTAL contact (a bell someone can never see is not a notification), on create and on re-dispatch, `wasChanged('vendor_id')` so an ordinary edit re-pings nobody | Closes the loop |

**Steps 1–3 were the useful minimum, and 4–6 followed the same day.** The whole of it converts
dispatch from an internal column change into an agreement with a timestamp, which is the substance
of the ServiceChannel loop.

### Two things the build changed about this design

- **§5's "never a draft" was not buildable as written** — `FacilityWorkOrder::STATUSES` has no
  pre-dispatch state. `VendorScope::VISIBLE_STATUSES` is an allowlist that today equals every status,
  so it narrows nothing; its value is that a status added later stays invisible to contractors until
  someone adds it deliberately. Corrected in §5 rather than left to imply a protection that is absent.
- **§8 step 5's "only the author differs" understated the work.** The author could not be
  represented: `submitted_by_user_id` is a FK to `users`. Two nullable columns now answer two
  questions — which of OUR staff transcribed it, and which of THEIR people sent it — because an
  operator reading a quote should be able to tell a phone call from a submission.

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
