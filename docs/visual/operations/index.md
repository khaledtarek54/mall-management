# Operations — keeping the mall running

<p class="eyebrow">The engine room</p>

Leasing brings the money in; **operations spends to keep the building worth renting** — and recovers what it can from tenants. It's four jobs at once: fixing what breaks (**requests**), preventing breakage (**plans**), recovering shared costs (**CAM & meters**), and keeping the **stock and suppliers** that fuel all of it.

## The reactive loop — when something breaks

<p class="sub">A tenant reports an issue; your team triages, works it, and closes it — every handoff tracked.</p>

<div class="flow"><div class="step"><span class="n">01</span><span class="t">Request</span><span class="d">A tenant raises an issue (portal, phone, WhatsApp…).</span></div><span class="arrow">→</span><div class="step"><span class="n">02</span><span class="t">Assign</span><span class="d">Acknowledge, set priority, assign a person or vendor.</span></div><span class="arrow">→</span><div class="step"><span class="n">03</span><span class="t">Work</span><span class="d">Fix it — consuming spare parts from stock, or a vendor.</span></div><span class="arrow">→</span><div class="step"><span class="n">04</span><span class="t">Resolve</span><span class="d">Mark resolved with notes; the tenant can rate it.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">05</span><span class="t">Close</span><span class="d">Closed for good (or auto-closed after 7 quiet days).</span></div></div>

## The recurring engines — running in the background

<p class="sub">Not everything waits for a complaint. Four scheduled scans keep operations honest on their own.</p>

<div class="emap"><div class="enode"><span class="name">Preventive maintenance</span><span class="role">raises work orders from due plans, so kit is serviced before it fails</span><span class="rels"><span class="rel">facility:generate-preventive</span></span></div><div class="enode"><span class="name">CAM recovery</span><span class="role">reconciles shared costs and bills each tenant their fair share</span><span class="rels"><span class="rel">cam:reconcile</span></span></div><div class="enode"><span class="name">SLA &amp; auto-close</span><span class="role">flags breached deadlines; closes long-resolved requests</span><span class="rels"><span class="rel">requests:scan-sla-breaches</span></span></div><div class="enode"><span class="name">Contract expiry</span><span class="role">retires vendor contracts the day they lapse</span><span class="rels"><span class="rel">vendors:expire-contracts</span></span></div></div>

<div class="rule"><span class="lbl">Invariant · every scan is idempotent + lock-safe</span>These run unattended, so they must never double-act. Each one <b>locks the row and re-checks the condition inside the transaction</b> before acting — run any of them twice and nothing happens the second time. It's the same discipline across the whole system.</div>

## The records, and how they connect

<div class="emap"><div class="enode"><span class="name">Tenant Request</span><span class="role">a tenant's issue or ask (any type)</span><span class="rels"><span class="rel">belongs to Tenant · Unit</span><span class="rel">assigned to User or Vendor</span><span class="rel has">consumes Stock</span></span></div><div class="enode"><span class="name">Vendor</span><span class="role">a supplier / contractor</span><span class="rels"><span class="rel has">has many Contract</span><span class="rel has">takes Requests</span></span></div><div class="enode"><span class="name">Warehouse · Item · Stock Movement</span><span class="role">the append-only stock ledger</span><span class="rels"><span class="rel">Movement belongs to Warehouse · Item</span></span></div><div class="enode"><span class="name">Maintenance Plan → Work Order</span><span class="role">the preventive schedule and the jobs it raises</span><span class="rels"><span class="rel has">Plan has many Work Order</span></span></div><div class="enode"><span class="name">CAM Pool → Allocation</span><span class="role">a year's shared costs, split per lease</span><span class="rels"><span class="rel has">Pool has many Allocation</span></span></div><div class="enode"><span class="name">Utility Meter → Reading</span><span class="role">a metered point and its dated readings</span><span class="rels"><span class="rel has">Meter has many Reading</span></span></div></div>

## Go deeper

- **[Life of a request →](/operations/request-lifecycle)** — the full triage state machine
- **[CAM cost recovery →](/operations/cam-recovery)** — how shared costs get billed back, fairly
- **[Inventory in the books →](/operations/inventory-and-books)** — the stock ledger and what it posts
- **[Preventive maintenance &amp; vendors →](/operations/preventive-and-vendors)** — plans, work orders, contracts

_Full written rules: `docs/modules/08-cam.md`, `10-utility-meters.md`, `11-tenant-requests.md`, `12-vendors.md`, `22-inventory.md`, `26-facility.md`._
