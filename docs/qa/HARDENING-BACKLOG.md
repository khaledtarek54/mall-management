# Pre-prod hardening backlog — ✅ CLEARED

All findings from the two adversarial review passes this session are now **fixed
+ regression-tested**. Kept as a record. (~19 real bugs fixed across both passes
this session — see the git log + `docs/plans/02-qa-testing-plan.md`.)

> Worth a **third adversarial pass** to confirm convergence (pass 1 found 8, pass
> 2 found 11) — but with a lot of money-path code changed, review those commits
> first.

| ID | Severity | Finding | Status |
|----|----------|---------|--------|
| H1 | HIGH money | Cancelling a credited invoice leaked the applied credit | ✅ offsetting credit note issued on cancel (`76bc843`) |
| H2 | HIGH security | Attachments on a public disk = cross-tenant file disclosure | ✅ private disk + authed tenant-scoped stream route (`99c87e3`) |
| M1 | MEDIUM | Reconciliation showed gross AR, ignoring unapplied credits | ✅ `creditOutstanding` + `netAR` control totals (`bb1ceed`) |
| M2 | MEDIUM | Monthly-billing concurrent double-bill (sync path) | ✅ per-period `Cache::lock` (+ `WithoutOverlapping` on the job) (`bb1ceed`) |
| L1 | LOW | `LateFeeService` wrote invoice balance directly | ✅ now via `recomputeTotals()` (`bb1ceed`) |

**Earlier pass-2 fixes:** CAM credit auto-applies + lock-safe credit-note apply +
untracked stray SQLite (`8a0a5c6`); device-token cross-tenant leak + marketing-budget
soft-delete crash (`a51952a`); CAM allocation clobber lock (`91fb4be`).

**Pass-1 fixes (8):** prorated double-billing, negative-CAM credit loss, admin
per-type SLA, API stray sub-category, migration `down()`, ungated comment actions,
comment-on-terminal, + the type-system foundation. (`a3da086` → `c2542d7`.)
