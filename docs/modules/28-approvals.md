# 28 · Approvals — the value → approver ladder

> **Status:** engine shipped; **three consumers live** — spare-part draws (`WorkOrderPartService`),
> purchase requests on all four verbs (`PurchaseRequestService`), and owner disbursements
> (`DisbursementService`). *(This line said "one consumer" until 2026-08-11; the other two shipped
> after it was written.)* The **amounts are a default
> and need operator sign-off** — see [BUSINESS-RULES.md](../BUSINESS-RULES.md#approval-ladder-fr-cm-11--needs-operator-sign-off).

**Purpose.** Answer one question, in one place: *"does this amount need signing off, and by whom?"*
(FR-CM-11, FR-PROC-02).

Before this, the codebase had **no approval workflow of any kind** — only flat `approve()` booleans
on `VendorBill` and payroll, each meaning "somebody with this module's permission said yes". Nothing
anywhere made authority depend on *how much money was involved*.

---

> **⚠️ Fixed 2026-08-11 — the ladder was never installed in production.**
> `ApprovalRulesSeeder` was reachable only from `DatabaseSeeder`, the dev/demo chain;
> `atriom:install` seeded roles and accounting and nothing else. So on every real install
> `approval_rules` was **empty**, and an empty ladder is fail-open *by design*:
> `ApprovalPolicy::permissionFor()` returns null and `canApprove()` returns true for **any**
> amount. FR-CM-11 (spare-part tiers) and FR-PROC-02 (purchase-request tiers) did not exist in
> production.
>
> Scope it precisely: **base RBAC still applied** — the purchase-request action is
> `->authorize()`-gated with a self-approval exclusion, `WorkOrderPartService` calls
> `assertMayDecide()` first, and `DisbursementService` re-checks the frozen permission. What was
> lost is the **value tiering**, plus the audit trail: `required_permission` froze as `null`, so a
> decision could not record which tier it was supposed to need.
>
> **The suite could not catch it** — 16 test files seed the ladder themselves, so every approval
> test ran against a state production never reached. That is the project's own "tests must use
> reachable inputs" rule failing at scale. `InstallSeedsApprovalLadderTest` now asserts both
> halves: that an empty ladder really is fail-open, and that a real install is not in that state.
>
> `DepartmentSeeder` had the same gap with a worse shape — `DepartmentResource::canCreate()`
> returns false because the set is "seeded", so an install that skipped it had an empty table
> **forever with no in-app remedy**, and tenant-request auto-routing permanently off. Both now run
> from `atriom:install`.

## 1. Domain model

### `approval_rules` — one band of the ladder
| Column | Meaning |
|--------|---------|
| `module` | what is being approved (`ApprovalRule::MODULES`; `inventory_draw` today) |
| `min_amount` (inclusive) · `max_amount` (exclusive, null = unbounded) | the band |
| `required_permission` | who may sign off in that band — a **permission**, not a role |
| `is_active` | retire a band without deleting the history of what it used to say |

**Operator-wide, with no `asset_id`.** Unlike SLA — which the FRD explicitly wants set per mall —
approval authority is a company policy, not a property's. Don't add the dimension speculatively.

**Tiers are permissions** (`approvals.tier_1/2/3`), not named roles, so the ladder composes with the
existing RBAC instead of standing up a parallel one: a role gains authority by being granted a tier.
`super_admin` holds all three; `manager` holds 1–2 (tier_3 withheld deliberately); `operations`
holds tier_1.

**A new approvable module is a row + a constant, never a migration.**

---

## 2. Business rules

`App\Support\ApprovalPolicy` is the **sole reader** of `approval_rules`. Nothing else should read
that table — a second interpretation of the ladder is exactly how two parts of a system come to
disagree about who may approve a 5,000 EGP draw.

1. **A single approver resolved by amount — not a sequential chain.** The FRD only ever says
   "higher-value parts require higher-level approval", which is a *level lookup*. A chain is a much
   larger thing (routing, delegation, partial states) and the FRD's own open items flag
   procurement's hierarchy as unconfirmed. Build the simpler thing that's actually specified.
2. **Authority is cumulative.** Someone holding a higher tier may approve a lower band — otherwise
   a manager would be *blocked* from approving a small draw a supervisor could handle. That is the
   difference between a ladder and four disconnected locks.
3. **No ladder for a module = that module isn't gated.** `permissionFor()` returns null. Deliberate:
   an operator who hasn't configured procurement approval shouldn't find procurement unusable.
4. **A gap fails closed — to the *strictest* tier, not the last band.** If no band covers an amount
   (someone edits the bands and leaves a hole, or a value lands above everything), the answer is the
   most senior tier configured for the module. Note *strictest*, not *highest band*: nothing forces
   a band's tier to rise with its amount, so a ladder configured out of order (0–100 → tier_3,
   5000+ → tier_1) would hand a gap the **weakest** tier — failing open, in the one mechanism whose
   whole job is to fail closed. A permission outside the tier list is likewise treated as strictest:
   an unrecognised requirement is not a licence to skip it.
5. **`canApprove()` is not an action gate.** ⚠️ It answers the approval question *only*. When a
   module has no ladder, nothing needs approving, so it returns true for **any** signed-in user —
   viewer included. **Every caller must separately check that the user may perform the action at
   all.** This is not theoretical: the first consumer checked `canApprove()` alone, and deleting the
   bands let a read-only `viewer` approve a 50,000 EGP stock draw (proven, then fixed and pinned by
   a regression test). Use `isRequired()` for "does this need signing off?".
6. **Freeze the requirement onto the record.** A consumer should store the resolved
   `required_permission` at request time. Re-resolving at decision time lets an edit to the bands
   rewrite history about who was supposed to sign off.

---

## 2b. Editing the ladder (2026-08-11)

The bands were enforced from day one and could only be changed by a seeder and a deploy — so the
ladder the FRD calls *company policy* was, in practice, a developer's constant, and a policy nobody
can change without engineering is one that stops matching how the company actually signs things off.

**`ApprovalRuleResource`** — Settings → **Approval Bands**. Module, amount band, approver tier,
active. Four things about it are deliberate:

- **One permission, not four.** `approvals.manage_rules` already existed and is described as
  "configure the approval bands"; you either administer the ladder or you do not, and a view-only
  band list helps nobody. So the resource gates explicitly rather than through `RoleGatedActions`,
  whose `{module}.{action}` convention would have invented four permissions saying nothing the one
  already says. It is withheld from `manager` in the seeder for the same reason `approvals.tier_3`
  is: **a ladder whose rungs the people climbing it can rewrite is not a ladder.**
- **Shared, not property-scoped.** `ApprovalRule` carries no `asset_id` and is registered SHARED in
  `PropertyIsolation` — approval authority is a company rule, unlike SLA, which the FRD wants per
  mall. The panel is still property-tenanted, so the route keeps its `{tenant}` segment; the
  resource opts out of the auto-scope, not out of the URL.
- **Deleting or deactivating a band makes the gate STRICTER, never looser** — with no band covering
  an amount, `ApprovalPolicy` falls back to the strictest tier configured for that module. That
  fail-closed property is why these rows are classified as deletable configuration rather than
  guarded like a money record, and it is pinned by a test rather than assumed.
- **No overlap guard, on purpose.** Overlapping bands already resolve to the *strictest* covering
  band (the F-99 fix), so an operator widening one band without removing another gets a harder gate,
  not an ambiguous one. Adding a refusal would forbid a state that is already safe.

## 3. Consumers

| Module | What it approves | Where |
|--------|------------------|-------|
| [26 Preventive maintenance](26-preventive-maintenance.md) | an internal spare-part draw (FR-CM-10/11) — stock moves only on approval | `WorkOrderPartService` |
| Procurement (FR-PROC-02) | purchase requests | ⬜ planned |
| Permits (FR-REQ-*) | fit-out permits | ⬜ planned |

**The pattern for a new consumer:** resolve + freeze `required_permission` at request time → on
decide, check the **base action permission first**, then `ApprovalPolicy::canApprove()` against the
frozen value → block self-approval → only then perform the effect.

---

## 4. Gotchas

- **The bands must tile the line** (0→1k→10k→∞): min inclusive, max exclusive, no gap, no overlap.
  `ApprovalPolicy` fails closed if a gap appears, but a ladder that needs its safety net to be
  correct is one bad edit from surprising someone.
- **No Filament resource yet** — the bands are seeded (`ApprovalRulesSeeder`) and editable only in
  the database. Deliberate for now: the amounts are unsigned-off defaults, and a UI inviting the
  operator to tune numbers nobody has agreed to is worse than no UI. Build it with the sign-off
  (`approvals.manage_rules` already exists for it).
- **Self-approval is the consumer's job, not the policy's.** `canApprove()` takes an amount, not a
  request; it cannot know who raised the thing. Every consumer must block it — the control the FRD
  asks for is a *second pair of eyes*, and without it anyone holding tier_1 self-serves their own
  low-value requests.

---

## 5. Tests

`tests/Feature/Scenarios/ApprovalPolicyScenarioTest.php` — band resolution, cumulative authority,
the no-ladder case, and the fail-closed paths (a gap, and bands configured out of order — the case
where "last band" would have failed open).

The ladder's *behaviour in anger* is covered by its consumer:
`tests/Feature/Scenarios/WorkOrderPartsScenarioTest.php`.

**Related:** 26 Preventive maintenance (the live consumer), 22 Inventory (the stock a draw moves),
18 RBAC (the tier permissions).
