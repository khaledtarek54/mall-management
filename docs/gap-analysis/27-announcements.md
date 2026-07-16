# Module 27 — Announcements

> **Round 2**, audited 2026-07-16 — first ever gap analysis. Spec:
> [../modules/27-announcements.md](../modules/27-announcements.md) · methodology: [000-plan.md](000-plan.md).

**Status: 🟡 Yellow.** Small and correct on every path exercised — **no hard defect found.** The real
story is coverage: **8 tests** for a module that fans out to every tenant in a property.

This is a module where the honest answer is "it's fine, and we can't prove it".

---

## 1. Findings

### 🟢 F-97. The fan-out filters on lease status, never tenant status
`app/Services/SendAnnouncementAction.php:26`

`Tenant` has its own `status`, and `Tenant::canAccessPanel():89` requires `status === 'active'`. A
tenant whose own status is inactive but who still holds an `active` lease gets bell rows + push they
**can never log in to read**. Under-delivers/wastes only — **never leaks** — and it matches the
doc's stated definition, so this is a **spec question, not a bug**. Recorded so the next reader
doesn't re-derive it.

### 🟢 F-98. `recipients_count` can under-report
`SendAnnouncementAction` — `notifyPortal()` notifies the Tenant *then* each portal user; if a user's
`notify()` throws, the whole call is caught and the tenant is counted as **failed** even though the
Tenant itself was already notified. Cosmetic; the count is advisory.

---

## 2. Verified-correct — don't re-audit

These were the leading hypotheses. All were **checked and disproved** — recorded so nobody spends
the time again:

- **The fan-out is correctly property-scoped** — `whereHas('activeLeases.units', units.asset_id =
  target)`.
- **The ALL pseudo-asset cannot be picked.** `TenantScope::selectableAssetOptions():246` excludes it
  by code. *(This was the leading hypothesis — a broadcast to every tenant in the portfolio — and it
  is genuinely blocked. It's also the trap recorded in the module doc: never set
  `$tenantOwnershipRelationshipName`, whose creating hook would clobber `asset_id` to the ALL
  pseudo-asset.)*
- **`tries=1` genuinely blocks the double-blast.** Laravel fails a job exceeding `maxAttempts`
  **before** `handle()` runs, so a `retry_after` expiry cannot re-blast. Checked in the framework
  rather than assumed.

---

## 3. Test gaps — the actual finding for this module

**8 tests, and the rules with the most subtle reasoning have zero coverage:**

- **Nothing covers business rule 6** — the per-recipient try/catch, the
  `announcement.recipient_failed` OpsLog line, or **`sent_at` being stamped on a *partial* blast**.
  That is the rule carrying the most design reasoning and the least proof.
- **Untested: soft-deleted-unit under-delivery** (gotcha 4 in the module doc).
- **Untested: tenant-status vs lease-status** (F-97).

Compose-is-send means there is no draft state and no undo: **a wrong blast cannot be recalled.** For
a module with that property, 8 tests is thin — the risk isn't a known bug, it's that a future change
has nothing to catch it.

## 4. Deferred

- **D-86** — cover business rule 6: partial-blast `sent_at`, the per-recipient catch, and the
  OpsLog line.
- **D-87** — decide F-97: should the fan-out filter on tenant status as well as lease status? (Spec
  question for the operator — currently it matches the doc.)
