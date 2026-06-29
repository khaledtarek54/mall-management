# Test cases — <Module name>

> Copy this file to `NN-<module>.md`. One row per test case. Execute per release;
> mark **Result** ✅ pass / ❌ fail / ⏭️ skipped, with **tester** + **date**.
> A ❌ → log a bug, fix, add a regression test (`tests/Feature/Regression/`), re-run.

**Module doc:** [docs/modules/NN-*.md](../../modules/) · **Last full run:** `____` by `____`

## Conventions
- **Roles** to test against (admin panel): `super_admin · manager · viewer · leasing · operations · accounting · marketing · hr`; portal: `tenant-admin · tenant-staff (read-only)`; API: `tenant`.
- For every feature cover: **happy path · negative/validation · boundary · each role's permission (view/create/edit/delete + module actions) · property-scoping · i18n (en/ar)**.

## Cases

| # | Area | Case (steps) | Role | Expected result | Result | Tester / date |
|---|------|--------------|------|-----------------|--------|---------------|
| 1 | <area> | <do X with Y> | <role> | <observable outcome> | | |
| 2 | <area> | <negative: invalid input> | <role> | <validation error, no write> | | |
| 3 | <area> | <permission: forbidden role> | <role> | <action hidden / 403 / 404> | | |
| 4 | <area> | <scoping: other property> | <scoped role> | <not visible> | | |

## Exploratory charter
> Time-boxed (~30 min) free exploration. Note anything surprising.
- **Charter:** "<try to break X / abuse Y>"
- **Notes:**
