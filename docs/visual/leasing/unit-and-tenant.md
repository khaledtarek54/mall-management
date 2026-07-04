# Units &amp; tenants

<p class="eyebrow">Two supporting lifecycles</p>

The lease is the star, but two records quietly track state around it: the **unit** (is this space earning?) and the **tenant** (are we happy to do business with them?).

## A unit's status

<p class="sub">Occupancy at a glance — and, apart from maintenance, the lease drives all of it.</p>

<div class="track"><span class="pill p-grey">Vacant<small>empty · lettable</small></span><span class="conn">→</span><span class="pill p-amber">Reserved<small>lease being signed</small></span><span class="conn">→</span><span class="pill p-green">Occupied<small>live lease</small></span><span class="conn">↺</span><span class="pill p-grey">Vacant<small>lease ended</small></span></div>

<div class="branch"><div class="row"><span class="pill p-red">Under maintenance</span><span>Pulled out of the lettable pool while work happens — the one status you set by hand, not the lease.</span></div></div>

<div class="plain">A property's <b>Vacant</b> count is your headline occupancy metric — it's the red badge in the sidebar and what the owner's dashboard watches. Atriom Walk runs ~86% occupied; the demo's second property, Plaza Annex, is all-vacant on purpose to show scoping.</div>

## A tenant's standing

<p class="sub">Not a sequence — a standing that governs whether you'll lease to them at all.</p>

<div class="branch" style="border-top:none;padding-top:4px;"><div class="row"><span class="pill p-green">Active</span><span>In good standing — can hold leases, be invoiced, and use the tenant portal.</span></div><div class="row"><span class="pill p-grey">Inactive</span><span>Dormant — no live leases right now, but the relationship and history are kept.</span></div><div class="row"><span class="pill p-red">Blacklisted</span><span>Barred from new business — a serious default or dispute in their history. A flag you set deliberately.</span></div></div>

<div class="plain">A tenant is a <b>company or individual</b>, separate from the people who log into the portal (those are <em>tenant users</em>, and only an admin one may pay or raise requests). One tenant can hold several leases across your properties — which is why the tenant, not the lease, is the party on every invoice, payment, and credit note.</div>

_Source of truth: `docs/modules/01-properties-units.md`, `02-tenants.md`, `03-tenant-portal-users.md`._
