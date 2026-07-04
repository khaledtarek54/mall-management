# Life of a request

<p class="eyebrow">A lifecycle</p>

A tenant request is any issue or ask a retailer raises — a broken AC, a complaint, an access request, a billing query. Whatever the type, it moves through the same tracked state machine, so nothing gets lost in a WhatsApp thread.

## The path from raised to closed

<p class="sub">Left to right is the normal life of a request that gets handled.</p>

<div class="track"><span class="pill p-amber">Submitted<small>just raised</small></span><span class="conn">→</span><span class="pill p-teal">Acknowledged<small>seen · triaged</small></span><span class="conn">→</span><span class="pill p-teal">In progress<small>being worked</small></span><span class="conn">→</span><span class="pill p-green">Resolved<small>fixed</small></span><span class="conn">→</span><span class="pill p-grey">Closed<small>done</small></span></div>

Assigning a submitted request to someone **auto-acknowledges** it. Only the moves in the state machine are legal — an illegal jump (say, submitted straight to closed) is rejected outright.

## The two states that aren't a straight line

<div class="branch"><div class="row"><span class="pill p-amber">Awaiting tenant</span><span>The ball is in the tenant's court (you need info, or access). Their reply bounces it back to <b>In progress</b> automatically.</span></div><div class="row"><span class="pill p-red">Cancelled</span><span>Withdrawn or raised in error — a terminal, immutable end.</span></div></div>

<div class="rule"><span class="lbl">Rule · "Resolved" is not the end</span>This is the subtle one. Marking a request <b>Resolved</b> does <em>not</em> lock it — if the tenant comments or says "still broken," it <b>re-opens to In progress</b>. Only <b>Closed</b> and <b>Cancelled</b> are truly terminal and immutable (you can't re-assign or comment on them). A resolved request that stays quiet for <b>7 days auto-closes</b> itself.</div>

## Every request carries a type

<p class="sub">One state machine, many kinds of request — each with its own routing and deadlines.</p>

<div class="emap"><div class="enode"><span class="name">Maintenance</span><span class="role">something to fix — routed to the maintenance team, with an SLA clock</span></div><div class="enode"><span class="name">Complaint · Inquiry</span><span class="role">a grievance or a question</span></div><div class="enode"><span class="name">Access · Billing · Document</span><span class="role">a gate pass, an invoice query, a paperwork request</span></div></div>

<div class="plain">The <b>type</b> is a plain field, not a rigid database enum — so adding a new kind of request needs <em>no</em> migration. Each type carries its own SLA target, default department, and reference prefix. A maintenance request gets a <b>resolution deadline</b> (the SLA); the breach scan flags it if it slips, and the tenant can leave a 1–5 <b>satisfaction rating</b> once it's resolved.</div>

_Source of truth: `app/Services/TenantRequestService.php` (the TRANSITIONS map) and `docs/modules/11-maintenance.md`._
