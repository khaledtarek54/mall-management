# Test cases — Tenant Requests (incl. Maintenance)

> Worked example covering the generalized tenant-request system (maintenance +
> complaint / inquiry / access / billing / document). **Module doc:**
> [docs/modules/11-tenant-requests.md](../../modules/11-tenant-requests.md).

**Last full run:** `____` by `____`

## Conventions
Roles — admin: `manager · operations · accounting · leasing · viewer`; portal: `tenant-admin · tenant-staff`; API: `tenant`. Cover happy / negative / boundary / permission / scoping / i18n for each.

## Cases

| # | Area | Case (steps) | Role | Expected result | Result | Tester / date |
|---|------|--------------|------|-----------------|--------|---------------|
| 1 | Intake (admin) | Create a **maintenance** request; pick a sub-category (electrical) | operations | Saved; reference `MR-…`; routed to Operations; SLA target set per priority | | |
| 2 | Intake (admin) | Create an **inquiry** (no sub-category) | manager | Saved; reference `IQ-…`; **no SLA deadline**; sub-category field hidden | | |
| 3 | Intake (admin) | Create a **billing query** | accounting | Reference `BQ-…`; auto-routed to **Accounting** | | |
| 4 | Type switch (form) | Change Request Type in the create form | manager | Reference prefix updates live; sub-category options change / hide; stale sub-category cleared | | |
| 5 | Type immutability | Open an existing request for edit | manager | Request Type is **disabled** (can't change after creation) | | |
| 6 | Portal intake | Tenant submits a **complaint** (sub-category noise) | tenant-admin | Created; appears in "My Requests"; team notified | | |
| 7 | Portal gating | Read-only portal user tries to create | tenant-staff | Create action **not available** | | |
| 8 | Mobile API | `POST /me/requests` with `requestType:"access"`, `category:"parking"` | tenant | `201`; `requestType=access`; reference `AR-…` | | |
| 9 | API back-compat | `POST /me/requests` with only `category` (no requestType) | tenant | `201`; defaults to `maintenance` (old app keeps working) | | |
| 10 | API validation | Complaint with a maintenance sub-category (`electrical`) | tenant | `422` — sub-category not valid for this type | | |
| 11 | State machine | Move submitted → resolved directly (illegal hop) via Change Status | operations | Rejected; only legal transitions offered | | |
| 12 | Terminal immutability | Edit / re-route / assign a **closed** request | operations | Actions hidden; service refuses to mutate | | |
| 13 | Routing | Re-route a request to another department | manager | Department updated; activity log records from→to | | |
| 14 | Comments | Tenant comments on an open request | tenant-admin | Staff notified; staff reply notifies tenant; **internal notes never shown to tenant** | | |
| 15 | Notifications copy | Resolve a **complaint** | operations | Tenant notification reads "**Complaint** …", not "Maintenance" | | |
| 16 | SLA scan | Run `requests:scan-sla-breaches` twice | — | Breached open SLA-bearing requests flagged once (idempotent); inquiry/billing (no SLA) never flagged | | |
| 17 | Auto-close | Run `requests:auto-close` | — | Resolved-past-window requests closed; idempotent | | |
| 18 | CSAT | Resolve a request, then rate it (portal "Rate" / `POST …/rate`) | tenant-admin | 1–5 + comment stored; **rating blocked** while not resolved/closed; re-rate overwrites | | |
| 19 | CSAT dashboard | View MallStats | manager | "Tenant Satisfaction" KPI reflects the average | | |
| 20 | Scoping | Operations user assigned to property A views requests | operations (A) | Only property-A requests; not property-B | | |
| 21 | Attachments | Tenant attaches a photo + a PDF; tries a .mp4 | tenant-admin | Image/PDF accepted; video rejected | | |
| 22 | i18n | Switch to Arabic on the requests list + create form | manager | Labels Arabic, RTL; type/sub-category/status localized | | |

## Exploratory charter
- **Charter:** "Abuse the request workflow — illegal transitions, cross-tenant ids in the API body, rating an open request, spoofing tenant_id, submitting every type with/without a sub-category."
- **Notes:**
