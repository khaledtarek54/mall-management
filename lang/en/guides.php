<?php

/**
 * In-app operator guidance, per screen.
 *
 * Four fields each, because they are the four questions actually asked:
 *   purpose — what this screen is, in one sentence
 *   steps   — how the everyday task is done
 *   affects — what changes ELSEWHERE, which is the thing nothing else in the app tells you
 *   rules   — the constraints that would otherwise surprise someone
 *
 * Keep each line short enough to read standing up. The module docs
 * (`docs/business-model/NN-*.md`) are the deep reference; this is the answer to "what am I looking
 * at and what happens if I touch it".
 */
return [
    'holidays' => [
        'purpose' => 'The days your people are not at work. Egypt\'s holidays cannot be calculated — the Eids move with the moon and a mid-week holiday is often shifted to the Thursday beside it — so this list is yours to keep, once a year.',
        'steps' => [
            'Add a row per date. Choose Closure for a day nobody works, or Short day for reduced hours — Ramadan is the case that exists for.',
            'Leave the property blank for a national holiday. Name a mall only when that one mall differs.',
            'Once a year, top the register up. The system seeds only the seven FIXED-date holidays, and only for the install year and the one after — so from the third year even those need adding.',
            'The moon-sighted dates are never seeded and must be added when announced: the two Eids, the Islamic New Year, the Prophet\'s birthday, Coptic Easter and Sham El-Nessim.',
            'Check the seeded dates against the decree each year. A mid-week public holiday is often moved to the neighbouring Thursday, so the nominal date is not always the day off.',
            'Deactivate a row rather than deleting it if you want it out of the way.',
        ],
        'affects' => [
            'SLA deadlines for any priority you have set to be measured in working time, under Settings → SLA. A job raised before a holiday gets those days back.',
            'Nothing about money dates. Invoice due dates, ageing and late-fee grace stay calendar days, because that is what the lease says.',
            'Never a job already running: each work order records the clock it was promised on when it is raised, so a holiday added today cannot re-time yesterday\'s work — or re-price a penalty.',
        ],
        'rules' => [
            'A property\'s own row beats the national one for that date. That is how one mall trades through Eid.',
            'A short day needs both an opening and a closing time, and must close after it opens.',
            'One row per property per date.',
        ],
    ],
    'vendor_scorecard' => [
        'purpose' => 'How each vendor has actually performed — jobs done, how fast, how often they missed the target, and what it cost them.',
        'steps' => [
            'Set the window you are judging. A renewal usually looks at the last twelve months.',
            'Read across a row rather than ranking on one column — fast and expensive is a different vendor from slow and cheap.',
            'Check the expired-documents column before you dispatch anything else to them.',
            'Export it to take into the renewal conversation.',
        ],
        'affects' => [
            'Nothing. Every figure is read back from work orders, SLA penalties and vendor documents you already recorded.',
        ],
        'rules' => [
            'There is deliberately no single score. Weighting speed against cost against compliance is your judgement, not the system\'s.',
            'A blank response or resolution time means the vendor never acknowledged or never completed — it is not zero hours.',
            'SLA breaches count every missed target, whether or not anyone chased a penalty for it.',
            'A vendor with no jobs and no penalties in the window is absent rather than shown as a row of zeroes.',
            'Only the selected property is counted.',
        ],
    ],

    'properties' => [
        'purpose' => 'A mall you operate. Everything else in the system hangs off the property you have selected at the top of the screen.',
        'steps' => [
            'Record the property once: its name, code, city and lettable area.',
            'Add its floors, then its units, then its parking bays and other rentable items.',
            'Assign staff to it so alerts about its work reach the right people.',
        ],
        'affects' => [
            'The property picker at the top filters every screen in the system — a lease, invoice or work order you create belongs to the property you are in.',
            'Its lettable area is the denominator for occupancy and for CAM recovery shares.',
            'Owners attached here receive the statements generated from its books.',
        ],
        'rules' => [
            'A property cannot be deleted once anything references it. Deactivate it instead — its history has to stay readable.',
            'Parking bays are deliberately NOT counted in lettable area: a bay is licensed, not leased, and counting it would understate every occupancy figure.',
        ],
    ],

    'units' => [
        'purpose' => 'A shop, kiosk or other lettable space inside a property.',
        'steps' => [
            'Create the unit with its code, floor, category and measured area.',
            'Let it by creating a lease — the unit’s status follows the lease, you do not set it by hand.',
            'Record a re-survey with “Remeasure” rather than editing the area, so past periods keep the figure they were billed on.',
        ],
        'affects' => [
            'Area drives CAM recovery shares, occupancy percentages and any rent priced per square metre.',
            'A unit under an open expansion option or right of first refusal is flagged in the lease form — someone else has a claim on it.',
            'Meters fitted here recharge to whoever occupies the unit at the time of the reading.',
        ],
        'rules' => [
            'One unit cannot be under two active leases at the same time. The lease form refuses it, and so does the database.',
            'Editing the area changes it from today. To change what a PAST period was billed on you would have to correct that period’s invoices — the measurement history is deliberately not rewritable.',
        ],
    ],

    'tenants' => [
        'purpose' => 'A retailer who trades in one or more of your malls.',
        'steps' => [
            'Record the tenant with their legal name, tax card and commercial register.',
            'File their compliance documents — above all the insurance certificate — with expiry dates.',
            'Give them portal access so they can see their invoices and raise requests.',
        ],
        'affects' => [
            'Their leases, invoices, payments and requests all hang off this record.',
            'Documents with an expiry are chased automatically: 30 days before, and again once lapsed.',
            'The tax card and address are what the e-invoice carries — an incomplete address will be refused by the tax authority.',
        ],
        'rules' => [
            'A tenant with any history cannot be deleted; set them inactive instead.',
            'A tenant can trade in several malls. You only see the leases and money for the property you are currently in.',
        ],
    ],

    'unit_ownerships' => [
        'purpose' => 'The register of units that were SOLD rather than let — who bought which shop, on what terms, and how it is being used.',
        'steps' => [
            'Record the buyer as a party first, set as a unit owner, then come here.',
            'Pick the unit, the tenure, and the date the owner took it on.',
            'Set how it is managed: the owner trades from it, lets it himself, we let it for him, or it stands empty.',
            'On a resale, END the existing ownership with a date and record a new one. Never delete the old row.',
        ],
        'affects' => [
            'A sold unit still owes the service charge — from HANDOVER, not from the contract date.',
            'A former owner keeps every invoice and statement that quoted them, which is why a resale ends a tenure instead of removing it.',
            'Where we manage the unit, the rent we collect becomes money owed back to the owner, less our fee.',
            'The service-charge basis you pick here decides how this unit is apportioned its share of the common cost.',
        ],
        'rules' => [
            'The owner is a party record, the same kind a retailer uses — that is what lets them be invoiced, take payments and see the portal.',
            'This is NOT the mall owner. A mall owner receives the property\'s net; a unit owner pays the service charge.',
            'An ownership cannot end before it starts. Equal dates are allowed — a sale can collapse on handover day.',
            'An ownership that has billed anything can no longer be deleted. Transfer it instead.',
        ],
    ],
    'leases' => [
        'purpose' => 'The contract between a tenant and a unit: what they occupy, for how long, and what they pay.',
        'steps' => [
            'Pick the unit and tenant, set the term dates and the rent.',
            'Add the charge schedule — rent, service charge, marketing levy — each dated from when it starts.',
            'Assign any parking bays or storage the tenant also takes.',
        ],
        'affects' => [
            'The monthly billing run raises invoices from this lease’s charge schedule. Nothing bills that is not on the schedule.',
            'Changing the rent does not overwrite it: the current row is closed and a new one opens, so last year’s rent stays true for last year.',
            'The escalation date drives an automatic annual increase — by percentage or by a fixed amount, within the collar if one was agreed.',
            'The unit’s status becomes occupied, and it stops appearing as available to let.',
        ],
        'rules' => [
            'A unit already under an active lease cannot be let again — expansion goes on the existing lease as an additional unit.',
            'A terminated, expired or cancelled lease is frozen. Correct it by renewing or reversing, never by editing.',
            'Base rent is VAT-exempt; service charge is standard-rated. That is set by the charge type, not by you.',
        ],
    ],

    'invoices' => [
        'purpose' => 'A bill issued to a tenant — usually by the monthly run, sometimes raised by hand.',
        'steps' => [
            'Run monthly billing to raise every active lease’s invoice for the period.',
            'Check the preview first: it shows what will bill and what will be skipped, and why.',
            'Issue the invoice — a draft has no accounting effect until you do.',
        ],
        'affects' => [
            'Issuing posts to the general ledger: debit receivables, credit revenue by charge type, credit VAT.',
            'Any credit the tenant holds on account is applied automatically, reducing what they owe.',
            'The balance feeds AR aging, the collections worklist and the owner’s statement for that property.',
        ],
        'rules' => [
            'An invoice is never deleted. Cancel it, credit-note it, or write it off — each leaves a document an auditor can follow.',
            'An invoice dated into a closed accounting period is refused, because the ledger entry could not be posted.',
            'A lease in its rent-free fit-out period bills nothing, and the run tells you that is why it was skipped.',
        ],
    ],

    'payments' => [
        'purpose' => 'Money received from a tenant, and which invoices it settles.',
        'steps' => [
            'Record the receipt with its date, amount and method.',
            'Allocate it across the tenant’s open invoices — oldest first is the usual practice.',
            'Leave any surplus unallocated; it becomes credit on account.',
        ],
        'affects' => [
            'Allocating reduces each invoice’s balance and posts debit cash or bank, credit receivables.',
            'Surplus becomes on-account credit, which is applied automatically to the tenant’s next invoice.',
            'A receipt cannot over-settle an invoice already paid by a credit note, on-account credit or a netted deposit — all four are counted.',
        ],
        'rules' => [
            'A captured payment is never deleted. Void it, which reverses the ledger entry and re-opens the invoice.',
            'A receipt dated into a closed period is refused.',
            'A post-dated cheque is not a payment until it clears — it lives in its own register until then.',
        ],
    ],

    'credit_notes' => [
        'purpose' => 'A document that reduces what a tenant owes — a billing error, an agreed concession, a return.',
        'steps' => [
            'Raise the note against the tenant, stating the reason.',
            'Issue it, then apply it to the invoice it corrects.',
            'To undo it, reverse the application — never raise a second, opposite note.',
        ],
        'affects' => [
            'Applying reduces the invoice balance and posts debit sales returns, credit receivables.',
            'The VAT on the note follows the invoice it corrects, so the tax return stays consistent.',
            'The tenant sees it against their invoice in the portal.',
        ],
        'rules' => [
            'Reversing un-applies the ORIGINAL note. Issuing an offsetting note instead double-counts the sales return and pushes receivables negative.',
            'A note dated into a closed period is refused.',
        ],
    ],

    'cam' => [
        'purpose' => 'The shared costs of running a property, and each tenant’s share of them.',
        'steps' => [
            'Open a pool for the year and record what it cost to run the mall.',
            'Generate allocations — each lease’s share of the pool by its area.',
            'Reconcile at year end: what was estimated against what was actually spent.',
        ],
        'affects' => [
            'A tenant who underpaid gets a recovery invoice; one who overpaid gets a credit note, automatically.',
            'Shares are time-weighted: a tenant who took extra space in November carries that space for the days they held it, not the whole year.',
            'The landlord’s unrecovered portion is reported separately, so the pool always ties out.',
        ],
        'rules' => [
            'Reconciling a year closes it. Corrections after that go through the recovery invoice, not by re-opening the pool.',
            'A cap or a base year, where the lease has one, limits what can be recovered — the excess is the landlord’s.',
        ],
    ],

    'sales_declarations' => [
        'purpose' => 'What a tenant on percentage rent declares they sold, and the overage it produces.',
        'steps' => [
            'The tenant declares through the portal, or you record it here.',
            'Review it against the breakpoint on their lease.',
            'Lock it once agreed — a locked declaration cannot be edited.',
        ],
        'affects' => [
            'Overage above the breakpoint bills as its own invoice, separately from rent.',
            'Sales feed the analytics screens and the occupancy-cost report.',
        ],
        'rules' => [
            'A missed declaration is estimated automatically so the rent can still be billed — an estimate is labelled as one and is not a figure the tenant stood behind.',
            'Voiding a locked declaration needs a stated reason, because it moves money already billed.',
        ],
    ],

    'rentable_items' => [
        'purpose' => 'Parking bays, storage rooms and signage faces — space you let that is not a shop.',
        'steps' => [
            'Register each item once with its code and monthly rate.',
            'Let it from the tenant’s LEASE, not from here — open the lease and use “Parking & rentable items”.',
            'Take it back with Release when the tenant gives it up.',
        ],
        'affects' => [
            'Letting an item adds one parking line to the lease’s charge schedule, so it bills through the ordinary monthly run.',
            'The rate you agree on the lease overrides the item’s asking rate.',
            'The item shows as taken and cannot be promised to another tenant.',
        ],
        'rules' => [
            'These are deliberately excluded from the property’s lettable area — a bay is licensed, not leased.',
            'One item cannot be held by two leases on the same day.',
        ],
    ],

    'utility_tariffs' => [
        'purpose' => 'The published price of electricity, water and gas — and the day each price came into force.',
        'steps' => [
            'Add the tariff with its English and Arabic name, and say which utility it prices.',
            'Open its price ladder and add the price, with the day it starts and the decree it comes from.',
            'On each meter, pick this tariff and clear the meter\'s own rate override so it follows the list.',
        ],
        'affects' => [
            'Every meter on this tariff prices its NEW readings from it, so a decreed rise is entered once instead of re-typed on every meter.',
            'A reading is priced at the rate in force on the READING\'s date, so a reading you back-fill from last month keeps last month\'s price.',
            'Readings already entered keep the cost they were saved with — nothing you do here re-prices a recharge that has already been raised.',
        ],
        'rules' => [
            'A price change is a new rung on the ladder, never an edit to the old one — that is what keeps a past reading explicable.',
            'A rung runs until the next one starts. There is no end date, so two prices can never both be in force on one day.',
            'A rate typed on the meter itself WINS over the tariff. Leave it blank unless that meter genuinely has its own negotiated price.',
            'A tariff with no price prices its meters at 0, and a zero-cost reading cannot be billed — which is the safe direction, not a silent free supply.',
        ],
    ],
    'tax_codes' => [
        'purpose' => 'Every tax you can charge or be charged, and the rate each one carries from a given day.',
        'steps' => [
            'Add the tax with its English and Arabic name, and say whether it applies to sales, purchases or a supplier payment.',
            'Open its rate ladder and add the rate, with the day it comes into force and the law it comes from.',
            'Switch it on. Charge codes can then be billed under it.',
        ],
        'affects' => [
            'Charge codes point at a tax code, so a rate entered here reaches every supply billed under it — with no deploy and no editing twelve rows.',
            'A rate is resolved from the DOCUMENT\'s date, so an invoice dated before a change still bills the rate that was in force when it was raised.',
        ],
        'rules' => [
            'A rate change is a new rung on the ladder, never an edit to the old one — that is what keeps a past document explicable.',
            'Most codes ship without a rate on purpose: the statutory figure is your accountant\'s to enter, not the software\'s to guess. The law to open is on the row.',
            'A taxable code cannot be switched on until it has both a rate and a posting account, so nothing can be offered and then bill nothing.',
            'Changing a rate affects what is billed from now on. Invoices already issued keep the rate they were billed at.',
        ],
    ],

    'failure_codes' => [
        'purpose' => 'The words your engineers use to record what went wrong, why, and what they did about it. Recorded when a job is marked done, this is what makes reliability reporting possible at all — which machines fail most, whether a fault was ever really fixed, and whether to repair or replace.',
        'steps' => [
            'Pick a type: problem is what was seen, cause is why, remedy is what was done.',
            'Give it a code and its name in both languages.',
            'Leave the trade blank to offer it on every job, or pick one to keep the pickers short.',
            'Deactivate a code you no longer use; it stays on the jobs that recorded it.',
        ],
        'affects' => [
            'The three pickers on the "Mark done" dialog offer these codes, filtered to the job\'s trade plus every code with no trade.',
            'Nothing here is required. A code nobody chose is better than one chosen to clear a validation, because the second looks like data.',
            'These are worth nothing on the day they ship and everything two years later — a reliability report built before the codes has nothing to read.',
        ],
        'rules' => [
            'A code is unique within its TYPE, not overall: "leak" is a legitimate problem and a legitimate cause, and one row cannot honestly serve both.',
            'A code recorded on a finished job cannot be deleted — deactivate it. Deleting strands the history every reliability figure groups by.',
            'The starter set is a starting point, not a description of your business. Replace it with the words your engineers actually use.',
        ],
    ],
    'trades' => [
        'purpose' => 'The kinds of work the mall needs done, and which contractor is eligible for each. The trade routes a job, suggests who to dispatch, and is the axis every maintenance-spend report groups by.',
        'steps' => [
            'Add a trade with a code and its name in both languages.',
            'Set the standard hourly rate — what an hour of this trade costs you.',
            'On each vendor, tick the trades they actually do.',
            'Deactivate a trade you no longer use; it keeps its history.',
        ],
        'affects' => [
            'Work orders, service plans and equipment all classify by trade — changing a name here renames it on every one of them, in both languages.',
            'The vendor picker on a work order opens on the contractors who do that trade. It still lets you pick another: eligibility is a suggestion, compliance is the gate.',
            'The hourly rate is what turns reported hours into job cost. A trade with no rate produces no labour cost — visibly missing rather than quietly wrong.',
        ],
        'rules' => [
            'The code is the stable key that reports and imports match on. Change it before it is used, not after.',
            'A trade that has routed work cannot be deleted — deactivate it. Deleting would strand the dimension every past spend report grouped by.',
            'An active trade with no eligible vendors is worth noticing: work of that kind has nobody to dispatch to.',
        ],
    ],
    'work_permits' => [
        'purpose' => 'Written authorisation for hazardous contractor work — hot work, isolations, height, confined spaces — and the record that it was closed out safely.',
        'steps' => [
            'Draft the permit: what work, exactly where, who is doing it, and the hours it is allowed.',
            'Write the conditions — fire watch, gas test, isolation certificate. The permit cannot be issued without them.',
            'Issue it. Your name goes on the authorisation.',
            'When the work stops, Close out and say what you checked.',
        ],
        'affects' => [
            'Nothing is billed or posted. This is a safety record.',
            'A permit cannot be issued to a contractor who is blacklisted or whose compliance documents have lapsed — the same rule that stops them being dispatched to a work order.',
            'Permits still open after their window are reported hourly to the property team, by email as well as the bell.',
        ],
        'rules' => [
            'Bound the hours to when the work actually happens. A permit good for a whole day is one somebody uses at 19:00 after the fire officer has gone home.',
            'A permit left open after its window is a finding, not a tidiness problem: it means nobody recorded that the work stopped and the area was made safe.',
            'Closing late is allowed and preferred over cancelling — cancelling means the work never proceeded, which is a different fact.',
        ],
    ],
    'rent_indices' => [
        'purpose' => 'The published index figures that CPI-linked rents escalate against — you record what was published, the system does the arithmetic.',
        'steps' => [
            'Add the month\'s figure when the statistical agency publishes it: the index code, the month it describes, and the value.',
            'Record the date it was published too — that is what explains a step months later.',
            'Nothing else to do. Every CPI lease reads this register on its own anniversary.',
        ],
        'affects' => [
            'Any lease with a CPI escalation clause steps its rent from these figures on its anniversary, then bills the new amount.',
            'A lease whose figure has not been published yet is left alone and retried daily — its rent does not step until the number exists.',
            'The increase is clamped by the floor and ceiling on the lease before any money moves.',
        ],
        'rules' => [
            'One value per index per month. A revision is an edit to that row, not a second row.',
            'The system never invents a figure. If the month is missing, the escalation waits rather than guessing.',
            'A lease measures against the index from its own lag — a clause reading "the September index, effective 1 January" is a four-month lag.',
        ],
    ],
    'charge_codes' => [
        'purpose' => 'The catalogue of things you can bill, and the account each one posts to.',
        'steps' => [
            'Add a code with its English and Arabic name.',
            'Choose the posting role it books to — that is what decides its account.',
            'It appears immediately as an option on any invoice line.',
        ],
        'affects' => [
            'Every invoice line carries one of these codes, and the ledger posts revenue according to the role you choose.',
            'The role resolves through the Posting Map, so a code inherits any per-property account override already set there.',
        ],
        'rules' => [
            'The code itself cannot be changed once saved — it is stored on every invoice ever billed under it. Change the name instead.',
            'Codes the billing engine relies on cannot be switched off or deleted.',
        ],
    ],

    'posting_map' => [
        'purpose' => 'Which account in your chart each kind of transaction posts to.',
        'steps' => [
            'Find the role — rent revenue, VAT payable, accounts receivable.',
            'Point it at the account your accountant wants it in.',
            'Add a property row only where one mall must post somewhere different.',
        ],
        'affects' => [
            'Every journal entry the system makes resolves its accounts through this map.',
            'Changing a role re-points everything posted from now on. It never rewrites entries already made.',
        ],
        'rules' => [
            'Only postable, active accounts can be chosen — the ledger refuses to post to a summary account.',
            'A global default cannot be removed, because nothing falls back behind it. Re-point it instead.',
        ],
    ],

    'areas' => [
        'purpose' => 'A zone of the mall — Ground Floor, Food Court, Parking, Roof Plant — used to route work to the people who own it.',
        'steps' => [
            'Create the zone with a short code and name.',
            'Assign its supervisors — the staff who should hear about work there.',
            'Put each unit in its zone, so requests raised about that unit route by themselves.',
        ],
        'affects' => [
            'A tenant request and a work order both inherit the zone of the unit they concern, and the zone\'s supervisors are notified.',
            'Common-area work has no unit, so its zone is the one you set on the work order.',
        ],
        'rules' => [
            'A code is unique within one mall, not across the portfolio — two malls may both have a “GF”.',
            'Supervisors can only be staff assigned to this property. You cannot route another mall\'s work to them.',
            'Retire a zone by deactivating it. Its history has to stay readable.',
        ],
    ],

    'deposits' => [
        'purpose' => 'Security deposits held against a lease — taken, refunded, or forfeited.',
        'steps' => [
            'Record the receipt when the tenant pays the deposit.',
            'On move-out, refund what is owed back, or forfeit what the lease entitles you to keep.',
            'Where the tenant leaves owing money, net the deposit against the outstanding invoice instead of refunding it.',
        ],
        'affects' => [
            'A deposit is a liability, not income: taking one posts debit cash, credit deposits held. It never touches revenue.',
            'Netting a deposit against an invoice settles that invoice — it is one of the four ways an invoice gets paid, alongside cash, credit notes and on-account credit.',
            'Forfeiting turns the liability into income; refunding clears it back out.',
        ],
        'rules' => [
            'A deposit transaction is never deleted. Reverse it with the opposite transaction, so the trail stays followable.',
            'You cannot refund or forfeit more than is held.',
            'A transaction dated into a closed accounting period is refused.',
        ],
    ],

    'post_dated_cheques' => [
        'purpose' => 'The register of cheques a tenant has lodged for future dates — common in Egypt, where a year of rent arrives as twelve cheques.',
        'steps' => [
            'Lodge the cheques, usually as one series covering the year.',
            'Watch the maturity list as each due date approaches.',
            'Clear a cheque when the bank honours it, or mark it bounced when it does not.',
        ],
        'affects' => [
            'Clearing a cheque creates the payment and settles the invoices it was lodged against — that is the moment it becomes money.',
            'A bounced cheque re-opens the invoice it would have paid, and the tenant owes it again.',
            'Nothing in AR, the ledger or the tenant\'s balance moves while a cheque is merely held.',
        ],
        'rules' => [
            'A held cheque is not a payment. It is a promise, and it is deliberately kept out of the books until it clears.',
            'A cheque cannot be linked to another tenant\'s invoices.',
            'A cheque is never deleted. Void it, which reconciles the register against what really happened.',
        ],
    ],

    'utility_meters' => [
        'purpose' => 'Electricity, water and gas meters, and the readings you recharge to tenants.',
        'steps' => [
            'Register the meter against the property, or against the unit it serves.',
            'Enter each reading — the consumption since the last one is worked out for you.',
            'Recharge the consumption to whoever occupied the unit on the reading date.',
        ],
        'affects' => [
            'A recharge becomes an invoice line to the tenant, and posts to the ledger like any other billed supply.',
            'Readings feed the consumption trend, which is how an unnoticed leak or a failing chiller shows up.',
        ],
        'rules' => [
            'One reading per meter per day. A second reading on the same date is refused.',
            'A reading lower than the one before it does not auto-fill — a meter reset or a misread has to be keyed deliberately.',
            'A reading that has already been billed is locked, because the invoice was raised on that figure.',
        ],
    ],

    'tenant_requests' => [
        'purpose' => 'Anything a tenant asks the operator for — a repair, a complaint, an access permit, a billing query.',
        'steps' => [
            'Log the request with its type and priority, or let the tenant raise it from the portal.',
            'Triage it to the department that owns it.',
            'Work it through acknowledged and in-progress to resolved, then close it.',
        ],
        'affects' => [
            'The priority sets the resolution deadline from your SLA settings, and the overdue scan chases it.',
            'The request inherits the zone of its unit, so the zone\'s supervisors are told about it.',
            'A repair that needs facility work becomes a work order, which tracks the job itself.',
        ],
        'rules' => [
            'A closed or cancelled request is frozen — it cannot be re-opened, re-assigned or re-routed. Raise a new one.',
            'A request always says who reported it: either a tenant, or a caller name when your staff log a phone call.',
            'The scheduled work window is a separate thing from the SLA deadline — work can be booked for next month on a request due this week.',
        ],
    ],

    'work_orders' => [
        'purpose' => 'A facility job — raised by a plan, by a tenant request, or by hand.',
        'steps' => [
            'Raise the order against the equipment or zone it concerns, and set its priority.',
            'Assign a technician or a vendor, and work the checklist item by item.',
            'Complete it, recording parts used and any vendor cost.',
        ],
        'affects' => [
            'Spare parts drawn from stock post to the ledger and reduce the warehouse balance.',
            'A vendor\'s job feeds their bill, and an SLA breach can cut a penalty from what you owe them.',
            'Preventive orders close the loop on the plan that raised them, and the plan schedules the next one.',
        ],
        'rules' => [
            'Common-area work has no unit — that is expected, and the zone is what routes it.',
            'A parts draw above your approval threshold waits for approval before stock moves.',
            'A completed order is a record of what was done. Correct it by raising a follow-up, not by editing history.',
        ],
    ],

    'service_plans' => [
        'purpose' => 'Recurring preventive maintenance — the schedule that raises work orders before something breaks.',
        'steps' => [
            'Create the plan against the equipment or zone it covers.',
            'Set how often it runs and what the technician must check.',
            'Leave it active — the daily scan raises the work order when it falls due.',
        ],
        'affects' => [
            'Each due date raises a work order automatically, with the checklist copied onto it.',
            'Completing that order sets the plan\'s next due date, so the cycle continues without anyone tracking it.',
        ],
        'rules' => [
            'The scan will not raise a second order while the last one is still open — a stalled job does not pile up duplicates.',
            'Deactivating a plan stops future orders. It does not touch orders already raised.',
        ],
    ],

    'equipment' => [
        'purpose' => 'The machines you maintain — chillers, escalators, pumps, generators — and how they break down into components.',
        'steps' => [
            'Register the machine with a code unique to this property.',
            'Add its components underneath it, so a motor sits under its escalator.',
            'Link it to the fixed asset it belongs to, where you depreciate it.',
        ],
        'affects' => [
            'Maintenance plans and work orders hang off equipment, so its history is the machine\'s service record.',
            'Spare parts consumed on its jobs build a running cost per machine.',
        ],
        'rules' => [
            'A component must live in the same property as its parent, and a machine cannot be its own ancestor.',
            'A machine with components cannot be moved to another property — move or detach the components first.',
            'Codes are unique per property, so two malls may each have an “ESC-01”.',
        ],
    ],

    'sla_policies' => [
        'purpose' => 'How long a facility job of each priority may take, at this property.',
        'steps' => [
            'Add a row only for a property that genuinely differs from your default.',
            'Set the hours to respond and the hours to resolve, per priority.',
            'Leave every other property alone — absence means the default applies.',
        ],
        'affects' => [
            'Work-order deadlines are set from these hours the moment the order is raised.',
            'The breach scan chases anything past its deadline, and a vendor\'s breach can be assessed as a penalty against their bill.',
        ],
        'rules' => [
            'A row here is an override, not a requirement. Restating the same four numbers on every property is exactly what this avoids.',
            'The four priorities match the ones on a work order and cannot drift apart from them.',
        ],
    ],

    'violations' => [
        'purpose' => 'The record that a tenant breached a mall rule — a blocked fire exit, unauthorised signage, after-hours noise.',
        'steps' => [
            'Record what happened, on what date, and the fine you assessed if there is one.',
            'Send the tenant a notice when you are ready to tell them formally.',
            'Bill the fine when you decide to charge it, and resolve the violation once it is dealt with.',
        ],
        'affects' => [
            'Billing the fine raises a VAT-exempt invoice against the tenant and posts it as miscellaneous income.',
            'Sending the notice stamps the date, so you can show when the tenant was told.',
        ],
        'rules' => [
            'Recording is not billing. A fine sits as a recorded figure until you explicitly bill it — nothing reaches the tenant\'s account before that.',
            'A fine bills once. To re-bill it you must first cancel the invoice it produced.',
            'Photographs attached as evidence are private, and are never served from a public link.',
        ],
    ],

    'vendors' => [
        'purpose' => 'The contractors, suppliers and service providers you buy from, and the contracts you hold with them.',
        'steps' => [
            'Register the vendor with its tax registration and commercial register.',
            'File its documents — above all the insurance certificate — with their expiry dates.',
            'Record the contract, its value and its term, scoped to a property where it only covers one mall.',
        ],
        'affects' => [
            'The contract value is a commitment: bills against it draw it down, so you can see what is left to spend.',
            'A withholding tax nature set here comes off every payment to this vendor automatically.',
            'Expiring documents and contracts raise a chase before they lapse, not after.',
        ],
        'rules' => [
            'A vendor with history is never deleted. Deactivate it — the bills and work orders that name it have to stay readable.',
            'Withholding is a nature from the tax catalogue, not a typed percentage. Mark a vendor exempt where they are outside it altogether.',
            'A contract expires by itself on its end date; renewals are recorded as a change, not by moving the old date.',
        ],
    ],

    'vendor_bills' => [
        'purpose' => 'What a supplier has charged you, and what you owe them.',
        'steps' => [
            'Record the bill against the vendor, with their document number and date.',
            'Link it to the purchase request it pays for, where there was one.',
            'Approve it, then pay it.',
        ],
        'affects' => [
            'Approving posts the expense and the payable: debit the expense or the asset, debit recoverable VAT, credit accounts payable.',
            'A bill linked to a purchase clears goods-received-not-invoiced instead of charging expense twice.',
            'Withholding tax is deducted at payment and held as owed to the authority, not paid to the vendor.',
            'An SLA penalty assessed against the vendor cuts what this bill pays.',
        ],
        'rules' => [
            'A vendor bill is never deleted. Reverse or credit it, so the payable trail survives.',
            'The tax the supplier charged is recorded as they charged it — picking the tax code fills the amount in, and departing from it by more than one pound needs a written reason.',
            'A bill dated into a closed period is refused.',
        ],
    ],

    'purchase_requests' => [
        'purpose' => 'A request to buy something — spare parts, consumables or a service — and the approval it needs first.',
        'steps' => [
            'List the items, the quantity, and why they are needed.',
            'Send it for approval; the value decides who has to approve it.',
            'Order it from a vendor once approved, then receive the goods when they arrive.',
        ],
        'affects' => [
            'Receiving catalogue items puts them into the warehouse and posts the stock into the books.',
            'The receipt creates goods-received-not-invoiced, which the vendor\'s bill later clears.',
            'The value is frozen at request time, so the approval tier cannot be dodged by editing it afterwards.',
        ],
        'rules' => [
            'A justification is required. A purchase nobody can justify is exactly what the approval exists to catch.',
            'A line is either a catalogue item or free text, never both — two descriptions would disagree about what was bought.',
            'You approve a need, then choose a supplier. The vendor is set at ordering, not at request.',
            'Rejected and cancelled are ends. Raise a new request rather than reviving one.',
        ],
    ],

    'inventory_items' => [
        'purpose' => 'The catalogue of spare parts and consumables you hold in stock.',
        'steps' => [
            'Add the item with its unit of measure and its standard cost.',
            'Set a reorder level so you are told before you run out.',
            'Receive stock against it through a purchase, and consume it on work orders.',
        ],
        'affects' => [
            'The standard cost values every consumption and write-off, and is what the stock on hand is worth in the books.',
            'Consumption on a work order charges maintenance expense and reduces the stock asset.',
        ],
        'rules' => [
            'The cost must be positive. At zero, every movement values at nothing and the stock ledger says the storeroom is empty when it is full.',
            'An item that has moved is never deleted. Deactivate it instead.',
        ],
    ],

    'stock_movements' => [
        'purpose' => 'Every receipt, issue, transfer and write-off — the stock ledger.',
        'steps' => [
            'Read it rather than write it: movements are created by receiving a purchase, drawing parts onto a work order, or writing stock off.',
            'Filter by warehouse, item or date to trace what happened to a part.',
            'Open a movement to see the document that caused it.',
        ],
        'affects' => [
            'Every movement posts to the ledger — a receipt raises the stock asset, a consumption charges expense, a write-off charges loss.',
            'The running balance per item and warehouse is the sum of these rows, so the stock value on the balance sheet comes from here.',
        ],
        'rules' => [
            'Movements are records of things that already happened. Correct them with an opposite movement, never by editing.',
            'A movement dated into a closed period is refused.',
        ],
    ],

    'warehouses' => [
        'purpose' => 'Where stock is physically held — a storeroom, a plant room, a van.',
        'steps' => [
            'Create the warehouse with a code unique to this property.',
            'Point purchase receipts at it so goods land in the right place.',
            'Draw parts from it when a work order consumes them.',
        ],
        'affects' => [
            'Stock balances are held per warehouse, so the same part has a separate quantity in each.',
            'A purchase request names the warehouse its goods will land in.',
        ],
        'rules' => [
            'Codes are unique per property. Two malls may each have a “MAIN”.',
            'A warehouse holding stock or carrying history cannot be deleted. Deactivate it.',
        ],
    ],

    'employees' => [
        'purpose' => 'The operator\'s staff at this property, their salary structure, and the advances they hold.',
        'steps' => [
            'Register the employee with their staff code, department and base salary.',
            'Record advances and loans, and the repayments that clear them.',
            'Terminate them when they leave — never delete them.',
        ],
        'affects' => [
            'An advance posts to the ledger as money owed by the employee, and repayments reverse it.',
            'The salary structure is what the payroll run reads when it generates payslips.',
            'Staff assigned to a property are who can be picked as a zone supervisor or a work-order technician there.',
        ],
        'rules' => [
            'No advance to a terminated employee, and no repayment beyond what is outstanding.',
            'The staff code is unique within a property.',
            'An employee with history is never deleted. Terminating keeps the payroll and advance record intact.',
        ],
    ],

    'payrolls' => [
        'purpose' => 'A payroll run for a period — the payslips, and the entry that books them.',
        'steps' => [
            'Open a run for the period and generate payslips from the salary structures.',
            'Adjust the lines while it is still a draft — allowances, deductions, advance repayments.',
            'Approve it, which books the cost and freezes the run.',
        ],
        'affects' => [
            'Approving posts the payroll: debit salary expense, credit net pay owed, credit each deduction to what it is owed to.',
            'Advance repayments taken through payroll reduce the employee\'s outstanding advance.',
        ],
        'rules' => [
            'Lines can only be changed while the run is a draft. Once approved, the run and its ledger entry are settled.',
            'A payroll run is never deleted. Reverse it if it was wrong.',
            'A run dated into a closed period is refused.',
        ],
    ],

    'custodies' => [
        'purpose' => 'Cash placed in a member of staff\'s hands to spend for the company — عهدة — and how it was spent.',
        'steps' => [
            'Grant the custody to the custodian, saying what it is for and which account the cash left.',
            'Settle it as they spend: each settlement is a categorised expense with its receipt.',
            'Return any unspent cash, which settles the balance.',
        ],
        'affects' => [
            'Granting posts debit custodies held, credit the cash or bank account it came from.',
            'Each settlement moves the money out of custody into the expense it actually was.',
            'Outstanding is what is granted less what is settled — it is derived, never stored.',
        ],
        'rules' => [
            'Settlements cannot exceed what is outstanding.',
            'No custody to a terminated employee.',
            'Once a custody has been settled against, its amount, date and source account are locked — changing them would misstate what the custodian still holds. The purpose and reference stay editable.',
        ],
    ],

    'expenses' => [
        'purpose' => 'Costs paid directly, without a supplier bill behind them — petty cash, fees, small purchases.',
        'steps' => [
            'Record what was spent, on what date, and which category it belongs to.',
            'Attach the receipt.',
            'Say which account the money came out of.',
        ],
        'affects' => [
            'Posting charges the expense account and credits the cash or bank it left.',
            'Recoverable VAT on the expense feeds the VAT return.',
            'An expense against a CAM pool becomes part of what is recovered from tenants.',
        ],
        'rules' => [
            'An expense is never deleted. Reverse it.',
            'An expense dated into a closed period is refused.',
        ],
    ],

    'departments' => [
        'purpose' => 'The operator\'s organisational backbone — HR, Marketing, Accounting, Leasing, Operations.',
        'steps' => [
            'Use the seeded five as they are; they are the routing targets the rest of the system expects.',
            'Assign staff to the department they work in.',
            'Triage tenant requests to the department that owns them.',
        ],
        'affects' => [
            'A request routed to a department reaches that department\'s staff, and its SLA clock is theirs to answer.',
            'Marketing budgets, purchases and expenses are attributed to the department that spent them.',
        ],
        'rules' => [
            'A department with staff, requests or spend behind it cannot be deleted. Deactivate it.',
        ],
    ],

    'owner_requests' => [
        'purpose' => 'Requests raised by a property\'s owner to your team, and your replies.',
        'steps' => [
            'Read the request and route it to whoever can answer it.',
            'Respond in writing.',
            'Close it once the owner has what they asked for.',
        ],
        'affects' => [
            'The owner sees your response against their request.',
            'Only the properties an owner currently holds are visible to them — a sold-off property drops out of their view.',
        ],
        'rules' => [
            'A responded or closed request is frozen. Raise a new one rather than editing an answer already given.',
            'Owners are staff users with an owner role, not a separate portal. What they see is decided by the properties they own.',
        ],
    ],

    'owner_statements' => [
        'purpose' => 'The periodic account you give a property\'s owner: income less expenses, and what they are owed.',
        'steps' => [
            'Generate a draft for the property and period — it reads the ledger, so it is regenerable.',
            'Check the figures against the income statement for the same period.',
            'Finalise it, which freezes the figures and books what the owner is owed.',
        ],
        'affects' => [
            'Finalising posts the distribution: what the owner is owed becomes a liability you can then pay.',
            'The statement is the basis for a disbursement — you cannot pay out against a draft.',
            'Once finalised, the period is evidence: revising it supersedes it with a new version rather than editing it.',
        ],
        'rules' => [
            'The figures come from the general ledger, not from a separate calculation, so the statement and the income statement agree by construction.',
            'A property with no owner assigned cannot be finalised — the draft is how you find that out.',
            'Corrections go through Revise, which creates a new version and marks the old one superseded.',
        ],
    ],

    'disbursements' => [
        'purpose' => 'Paying an owner what a finalised statement says they are owed.',
        'steps' => [
            'Schedule the payout against the finalised statement.',
            'Get it approved — the amount decides who has to approve it.',
            'Mark it paid, with the bank reference.',
        ],
        'affects' => [
            'Paying clears what was owed to the owner and takes the money out of the bank account.',
            'The statement shows what has been paid to date against it, so a part payment is visible.',
        ],
        'rules' => [
            'You can only pay against a finalised statement. A draft is disposable and its figures can still change.',
            'The approval tier is frozen when the payout is scheduled, so it cannot be dodged by editing the amount.',
            'A cancelled payout is an end. Schedule a new one.',
        ],
    ],

    'marketing_budgets' => [
        'purpose' => 'The marketing fund for a property and year, and what has been spent from it.',
        'steps' => [
            'Let the yearly budget accrue from the levy on base rent, or set it directly.',
            'Record each marketing activity against it as it is spent.',
            'Watch the balance — it turns red when the year is overspent.',
        ],
        'affects' => [
            'The marketing levy on every lease feeds this fund, so what you can spend follows what you billed.',
            'Spend recorded here is what the owner sees as marketing cost on their statement.',
        ],
        'rules' => [
            'The levy is a lease term — five per cent of base rent by default — and is set in Settings, not here.',
            'Overspending is allowed and made visible rather than blocked. It is a conversation, not an error.',
        ],
    ],

    'marketing_posts' => [
        'purpose' => 'Offers, events and mall news shown to shoppers in the visitor app.',
        'steps' => [
            'Write the post, or review one a retailer submitted from their portal.',
            'Set the dates it is valid and the dates it should be shown.',
            'Publish it once approved.',
        ],
        'affects' => [
            'A published post inside its display window is visible to shoppers in the app, with no login.',
            'A retailer\'s submission waits for your review — nothing a tenant writes reaches shoppers unseen.',
        ],
        'rules' => [
            'There are two date pairs and they mean different things: when the offer is valid, and when the post is shown. A sale can be advertised before it starts.',
            'This is the one surface shoppers can read without an account, so only the fields meant for them are ever sent.',
        ],
    ],

    'announcements' => [
        'purpose' => 'Mall news — a notice broadcast to every active tenant of one property, and kept as a post they can re-read.',
        'steps' => [
            'Write the notice in both languages and pick the property it goes to.',
            'Choose Send now, Schedule for a time, or Save as draft.',
            'Open a sent notice to see which tenants have actually opened it.',
        ],
        'affects' => [
            'Every active tenant of that property is notified in-app and by mobile push, and the notice joins their news feed in the app and the portal. Announcements are deliberately not emailed.',
            'A read receipt is recorded per tenant the first time they open it — that is the record behind "we told you".',
        ],
        'rules' => [
            'One announcement targets exactly one property. To reach two malls, write two.',
            'Tenants who are not active at that property do not receive it, and a tenant who moves in later does not see it.',
            'A sent notice cannot be edited — tenants already hold a push quoting its text. Correct it by sending another.',
            'Sending is a separate permission from composing, so a draft can be written by someone who cannot broadcast it.',
        ],
    ],

    'journal_entries' => [
        'purpose' => 'The double-entry record — both those the system posts for you and those you write by hand.',
        'steps' => [
            'Read entries here to see how a document reached the books.',
            'Write a manual entry only for something no document produces — an accrual, a correction, an opening balance.',
            'Post it once it balances.',
        ],
        'affects' => [
            'A posted entry moves the trial balance, the income statement and the balance sheet immediately.',
            'Most entries here are derived from a document. Changing the document re-derives its entry; the entry itself is not where you make the correction.',
        ],
        'rules' => [
            'Debits must equal credits. An unbalanced entry cannot be posted.',
            'A posted entry is never deleted or edited. Void it, which posts a reversal, and post a fresh one.',
            'An entry dated into a closed period is refused.',
            'An entry cannot post to a summary account — only to accounts marked postable.',
        ],
    ],

    'ledger_accounts' => [
        'purpose' => 'Your chart of accounts — the list of accounts every entry posts into.',
        'steps' => [
            'Add the account with the code and name your accountant uses.',
            'Say what type it is, and whether entries may post directly to it.',
            'Point the posting map at it so transactions land there.',
        ],
        'affects' => [
            'The account type decides which statement it appears on and which side of it increases.',
            'Summary accounts total their children on the reports but never carry entries themselves.',
        ],
        'rules' => [
            'An account that has been posted to is never deleted. Deactivate it.',
            'The chart is shared across every property. One mall posting somewhere different is set as an override on the posting map, not as a second account.',
            'Making a posted-to account non-postable does not move the entries already in it.',
        ],
    ],

    'accounting_periods' => [
        'purpose' => 'The months your books are divided into, and whether each one is still open.',
        'steps' => [
            'Open the period before work is posted into it.',
            'Do the month-end checks while it is open.',
            'Close it when the month is signed off.',
        ],
        'affects' => [
            'Closing a period refuses every new document dated into it — an invoice, a payment, a bill, a journal entry.',
            'Reports for a closed period stop moving, which is what makes them quotable.',
        ],
        'rules' => [
            'A closed period is refused at the point of saving, not silently later, so nothing commits and then fails to post.',
            'A missing period is allowed; only a closed one is refused. That is deliberate — a gap in the calendar should not stop work.',
            'Re-opening a closed period is an accounting decision, not a convenience. Prefer a correcting document in the current period.',
        ],
    ],

    'fixed_assets' => [
        'purpose' => 'What the operator owns and depreciates — plant, fit-out, equipment, vehicles.',
        'steps' => [
            'Register the asset with its cost, its date in service, and its useful life.',
            'Let the monthly run post depreciation.',
            'Dispose of it when it goes, recording any proceeds.',
        ],
        'affects' => [
            'Each month posts depreciation: charge the expense, build up accumulated depreciation.',
            'The register is the balance-sheet schedule — cost, accumulated depreciation and net book value come straight from it.',
            'Disposal writes off the remaining book value and books the gain or loss against the proceeds.',
        ],
        'rules' => [
            'Depreciation is straight-line from the in-service date over the useful life.',
            'A tag is unique within a property.',
            'A month already depreciated is not re-posted. The run is safe to repeat.',
        ],
    ],

    'bank_accounts' => [
        'purpose' => 'The bank and cash accounts money actually moves through.',
        'steps' => [
            'Register the account with its bank, number and currency.',
            'Point it at the ledger account it represents.',
            'Use it as the source or destination when recording receipts and payments.',
        ],
        'affects' => [
            'Every receipt, payment, payout and custody grant names one of these, and posts to the ledger account behind it.',
            'Bank statements are imported against an account and reconciled to its book balance.',
        ],
        'rules' => [
            'An account with movements is never deleted. Deactivate it.',
            'The ledger account behind it is what the books actually move — the bank details here are for identifying it.',
        ],
    ],

    'bank_statements' => [
        'purpose' => 'What the bank says moved, so you can agree it against what your books say moved.',
        'steps' => [
            'Import the statement for the account and period.',
            'Match each line to the posting that explains it.',
            'Reconcile once every line is matched and the closing balance agrees.',
        ],
        'affects' => [
            'Matching links a bank line to a book entry. It is the control that catches a payment recorded twice, or one never recorded at all.',
            'Reconciling is what lets you say the bank balance and the ledger balance are the same number.',
        ],
        'rules' => [
            'Importing posts nothing. A statement is the bank\'s account of what happened; matching it to the books is the separate, deliberate step.',
            'Re-importing an overlapping range is safe — the same row lands on the same record rather than doubling the statement.',
            'A bank\'s genuine duplicate — two identical fees on one day — is kept as two lines, because collapsing it would lose money from the evidence.',
        ],
    ],

    'approval_rules' => [
        'purpose' => 'The ladder that answers one question: does this amount need signing off, and by whom.',
        'steps' => [
            'Set the value bands and the permission each band demands.',
            'Check the ladder covers every band from zero upwards.',
            'Review it with the operator — the shipped figures are a starting point, not your policy.',
        ],
        'affects' => [
            'Spare-part draws, purchase requests and owner payouts all read this ladder before they proceed.',
            'The tier a document needs is frozen when it is raised, so editing the amount afterwards cannot lower the bar.',
        ],
        'rules' => [
            'An empty ladder approves everything. That is the state a fresh install must not be left in.',
            'The amounts here are a default awaiting the operator\'s sign-off, not a rule the system invented.',
        ],
    ],

    'users' => [
        'purpose' => 'Who may sign in to the operator\'s panel, and which properties they work at.',
        'steps' => [
            'Create the user and give them the role their job needs.',
            'Assign the properties they work at — that is what they will be able to see.',
            'Deactivate them when they leave.',
        ],
        'affects' => [
            'The role decides what they may do; the property assignment decides what they may see it on.',
            'A property\'s staff are who can be picked as its zone supervisors and work-order technicians.',
            'Owners are users too — an owner role plus the properties they own.',
        ],
        'rules' => [
            'A user with history is never deleted. Deactivating keeps every record that names them readable.',
            'Nobody sees a property they are not assigned to, whatever their role — except a super administrator.',
        ],
    ],

    'roles' => [
        'purpose' => 'What each job may do — the permission sets behind leasing, accounting, maintenance and the rest.',
        'steps' => [
            'Start from the seeded roles; they match the departments the operator actually runs.',
            'Grant a permission only where the job genuinely needs it.',
            'Give the user the role, rather than granting permissions one by one.',
        ],
        'affects' => [
            'Every screen, button and action checks a permission from here before it will run.',
            'Removing a permission takes the action away immediately, everywhere it appears.',
        ],
        'rules' => [
            'Deleting money records is not a permission that exists. An invoice, payment or journal entry is corrected through its own workflow, by anyone — including a super administrator.',
            'Departing from the tax catalogue is deliberately withheld from managers: it is an accounting act, not an operational one.',
        ],
    ],

    // ── Admin pages ───────────────────────────────────────────────────────────────────────────
    // The screens where the money questions actually get asked. They had no help at all until
    // 2026-08-12, which is the wrong way round: a resource form is largely self-describing, a
    // trial balance is not.

    'dashboard' => [
        'purpose' => 'The state of the property you have selected, at a glance.',
        'steps' => [
            'Switch property at the top — every figure here follows that choice.',
            'Read the cards for what needs attention today.',
            'Click a card to open the list behind it, already filtered to what the card counted.',
        ],
        'affects' => [
            'Nothing. This screen only reports; it changes no record.',
            'What you see depends on your role: the cards are chosen for the job, so an accountant and a maintenance supervisor do not get the same dashboard.',
        ],
        'rules' => [
            'Every figure is for the selected property only, not the portfolio.',
        ],
    ],

    'billing_run' => [
        'purpose' => 'A dry run of the month\'s billing — what will be invoiced, and what will be skipped, before anything becomes real.',
        'steps' => [
            'Pick the month and read the preview.',
            'Check the skipped rows and why they were skipped, not just the totals.',
            'Post the run once it looks right.',
        ],
        'affects' => [
            'Posting raises a real invoice for every listed lease, and each one posts to the ledger.',
            'Nothing bills that is not on a lease\'s charge schedule, which is why a missing row here is a lease problem, not a billing problem.',
        ],
        'rules' => [
            'Every row is worked out by the same code the real run uses, so what you see is what will post.',
            'A lease in its rent-free fit-out period bills nothing, and the preview says that is why.',
            'The run is for the selected property. Billing is a per-property act.',
        ],
    ],

    'rent_roll' => [
        'purpose' => 'Every occupied unit, who is in it, what they pay, and when their lease ends.',
        'steps' => [
            'Read it as the leasing position of the property today.',
            'Sort by expiry to see what is coming up for renewal.',
            'Export it when the owner or a valuer asks for it.',
        ],
        'affects' => [
            'Nothing. It reports the lease and charge records as they stand.',
        ],
        'rules' => [
            'It shows contracted rent, which is what the lease says — not what has been collected.',
        ],
    ],

    'revenue_forecast' => [
        'purpose' => 'What the portfolio will bill, month by month, from every lease already signed.',
        'steps' => [
            'Pick how far ahead to look — 6, 12, 24 or 36 months.',
            'Read the monthly totals, and the Basis column: invoiced months are settled, projected ones are not.',
            'Export to CSV for a column per charge type, which is what reconciles against a budget.',
        ],
        'affects' => [
            'Nothing. This page only reads — it changes no lease, raises no invoice and posts nothing.',
            'Every figure comes from the same method the monthly billing run uses, so a change to billing changes this forecast in the same release.',
        ],
        'rules' => [
            'Contracted income only. No assumed renewals and no re-lets of vacant space — those would need a renewal probability and a market rent, which this system does not hold, and a guessed figure on this page is indistinguishable from a real one.',
            'Net of tax. VAT is collected for the state, not earned, so including it would overstate every figure here.',
            'A month counts as invoiced only when every lease in it has been billed. One un-billed lease makes the whole month a projection.',
        ],
    ],
    'expiration_schedule' => [
        'purpose' => 'Which leases end when, so renewals are started before a tenant is out of contract.',
        'steps' => [
            'Look at the next few months first.',
            'Open a lease to renew it or record the tenant\'s decision.',
            'Check the option windows — a tenant with an unexercised option has a claim on the space.',
        ],
        'affects' => [
            'Nothing directly. It is the worklist that drives renewal conversations.',
        ],
        'rules' => [
            'A lease stays active right up to the day it lapses, so a renewal that is late does not look late on status alone. That is why this screen is a date window rather than a status filter.',
        ],
    ],

    'occupancy_map' => [
        'purpose' => 'The floor plan — every unit as a card, grouped by floor and coloured by status.',
        'steps' => [
            'Scan for vacant units by colour.',
            'Filter or search to find a unit or a tenant.',
            'Click through to the unit or its lease.',
        ],
        'affects' => [
            'Nothing. A unit\'s status follows its lease and is not set here.',
        ],
        'rules' => [
            'Parking bays and other rentable items are not units and do not appear here.',
        ],
    ],

    'occupancy_cost' => [
        'purpose' => 'What each tenant pays as a share of what they sell — the number that shows who is in trouble before they miss a payment.',
        'steps' => [
            'Read the percentage per tenant for the period.',
            'Look at the tenants above the amber and red bands first.',
            'Talk to them before the arrears arrive.',
        ],
        'affects' => [
            'Nothing. It reads invoices and declared sales.',
        ],
        'rules' => [
            'It only covers tenants who declare sales. A tenant with no declarations has no ratio, not a good one.',
            'The healthy band differs by trade — food courts run high, anchors run low — so the thresholds are a conversation, not a verdict.',
        ],
    ],

    'sales_analytics' => [
        'purpose' => 'What tenants are selling, by period and by trade.',
        'steps' => [
            'Choose the period you want to look at.',
            'Compare trades and tenants against each other.',
            'Follow a weak trend into the tenant\'s occupancy cost.',
        ],
        'affects' => [
            'Nothing. It reads sales declarations.',
        ],
        'rules' => [
            'Estimated declarations are included and labelled. An estimate is the system\'s figure, not one the tenant stood behind.',
        ],
    ],

    'ar_aging' => [
        'purpose' => 'How much tenants owe, and how late it is.',
        'steps' => [
            'Read the buckets — current, 30, 60, 90 days and beyond.',
            'Click a bucket to see the invoices inside it.',
            'Take the oldest money first.',
        ],
        'affects' => [
            'Nothing. It reads invoice balances.',
        ],
        'rules' => [
            'Balance is what is left after every way an invoice can be settled — cash, credit notes, on-account credit and netted deposits.',
            'A cancelled invoice carries no balance and is not aged.',
        ],
    ],

    'ar_aging_by_type' => [
        'purpose' => 'The same overdue money, split by what it is owed for.',
        'steps' => [
            'Compare rent against service charge, CAM and the rest.',
            'Use it to decide whether an overdue total is a collections problem or a dispute.',
        ],
        'affects' => [
            'Nothing. It is the AR aging figures re-cut by charge type.',
        ],
        'rules' => [
            'A single aging total is ambiguous: overdue rent needs a call, a disputed service charge needs a different conversation. This is the split that tells them apart.',
            'The grand total ties exactly to the AR aging summary — the per-line figures are derived from the same invoice balances.',
        ],
    ],

    'ar_collections' => [
        'purpose' => 'Who to call this morning, and about what.',
        'steps' => [
            'Work the list from the top — it is ordered worst-first.',
            'Open a tenant to see every invoice behind their total.',
            'Record what you agreed against the invoice.',
        ],
        'affects' => [
            'Nothing directly. It is the worklist, not the ledger.',
        ],
        'rules' => [
            'This is a different question from AR aging: that one asks how much is 31–60 days late, this one asks who to chase. A tenant 120 days late for a small sum needs the call before one 5 days late for a large one.',
            'The buckets are worked out the same way as on the aging report, so the two screens cannot disagree.',
        ],
    ],

    'trial_balance' => [
        'purpose' => 'Every account with its debit and credit total, for a period — the proof the books balance.',
        'steps' => [
            'Pick the period and the property, or read it consolidated.',
            'Check that debits equal credits.',
            'Drill into an account that looks wrong.',
        ],
        'affects' => [
            'Nothing. It totals the posted journal entries.',
        ],
        'rules' => [
            'It counts posted entries only. Draft entries are not in the books yet.',
            'A voided entry is not erased — it is offset by its reversal, and both appear.',
        ],
    ],

    'general_ledger' => [
        'purpose' => 'Every posting to an account, in order, with the document behind it.',
        'steps' => [
            'Pick the account and the date range.',
            'Follow the running balance to where it changed.',
            'Open the entry to see the document that caused it.',
        ],
        'affects' => [
            'Nothing. It is the detail behind the trial balance.',
        ],
        'rules' => [
            'Most entries are derived from a document. To correct one, correct the document — the entry is re-derived.',
        ],
    ],

    'income_statement' => [
        'purpose' => 'Revenue less expenses for a period — what the property earned.',
        'steps' => [
            'Choose the period and the property.',
            'Compare against the period before.',
            'Drill into a line to see the accounts behind it.',
        ],
        'affects' => [
            'Nothing. It reads the ledger.',
        ],
        'rules' => [
            'It is accrual: revenue is recognised when an invoice is issued, not when it is paid.',
            'The owner\'s statement is built from these same figures, so the two agree by construction.',
        ],
    ],

    'balance_sheet' => [
        'purpose' => 'What the property owns and owes on a given date.',
        'steps' => [
            'Choose the date and the property.',
            'Check that assets equal liabilities plus equity.',
            'Drill into a line for the accounts behind it.',
        ],
        'affects' => [
            'Nothing. It reads the ledger.',
        ],
        'rules' => [
            'Deposits held from tenants sit here as a liability, not as income. They are the tenant\'s money until forfeited.',
            'Fixed assets show at cost less accumulated depreciation, which comes straight from the asset register.',
        ],
    ],

    'cash_flow' => [
        'purpose' => 'Where cash actually came from and went, for a fiscal year.',
        'steps' => [
            'Choose the year and the property.',
            'Read operating, then investing, then financing.',
            'Check the closing figure against the bank.',
        ],
        'affects' => [
            'Nothing. It reads the ledger.',
        ],
        'rules' => [
            'It starts from profit and adjusts for non-cash items and working capital, so it reconciles to the movement in cash rather than restating it.',
            'Profit and cash are different questions. An issued invoice is revenue immediately and cash only when paid.',
        ],
    ],

    'vat_return' => [
        'purpose' => 'The VAT position for a period — what you charged, what you can reclaim, and the difference.',
        'steps' => [
            'Choose the period.',
            'Check the output and input figures against the documents behind them.',
            'Use it to prepare the filing.',
        ],
        'affects' => [
            'Nothing. It reports a position and files nothing.',
        ],
        'rules' => [
            'The figures come from the ledger, because that is the single source of truth. The documents are used to check it.',
            'Base rent is exempt and service charges are taxable. Which supplies are taxable is set on the charge code, not here.',
            'An invoice keeps the rate it was billed at, so a rate change never rewrites a past return.',
        ],
    ],

    'month_end_close' => [
        'purpose' => 'The checklist for closing a month, with each step showing whether it is done.',
        'steps' => [
            'Work down the rows — each shows a state, a count and a link to the thing that clears it.',
            'Clear every row before you close.',
            'Close the period from the Accounting Periods screen.',
        ],
        'affects' => [
            'Nothing on this screen changes a record. Closing the period is done where periods live, so there is one place to close and one gate to pass.',
        ],
        'rules' => [
            'Every count is derived from the service that owns that decision, so a row cannot claim done while the underlying work is outstanding.',
            'Closing the period refuses every later document dated into it.',
        ],
    ],

    'weekly_spend' => [
        'purpose' => 'What the property spent week by week, split into committed and variable cost.',
        'steps' => [
            'Choose the weeks you want.',
            'Compare fixed against variable to see what is actually controllable.',
            'Export it for the management pack.',
        ],
        'affects' => [
            'Nothing. It reads expenses and vendor bills.',
        ],
        'rules' => [
            'Figures are excluding VAT, so they compare against budgets rather than against cash paid.',
            'This is the only weekly report in the system — everything else is monthly or as-of.',
        ],
    ],

    'reports' => [
        'purpose' => 'The month\'s billing, collections and receivables position on one screen.',
        'steps' => [
            'Read the headline figures for the month.',
            'Check the ageing buckets underneath them.',
            'Follow revenue by charge type to see where the month came from.',
        ],
        'affects' => [
            'Nothing. It reads invoices and payments.',
        ],
        'rules' => [
            'Figures are for the selected property and the chosen month.',
        ],
    ],

    'report_hub' => [
        'purpose' => 'Every report in the system, with a sentence saying what each one answers.',
        'steps' => [
            'Read the descriptions rather than the titles — that is what tells two similar reports apart.',
            'Open the one that answers your question.',
        ],
        'affects' => [
            'Nothing. It is an index.',
        ],
        'rules' => [
            'A report appears here only if you may open it, so you will never meet a link that refuses you.',
        ],
    ],

    'workflows' => [
        'purpose' => 'The state machines the system enforces — every status, and what it may move to next.',
        'steps' => [
            'Pick the workflow you are asking about.',
            'Read which statuses follow which.',
            'A status with nothing after it is an end.',
        ],
        'affects' => [
            'Nothing. It is a reference.',
        ],
        'rules' => [
            'This is drawn from the same rules the services enforce, so it cannot show a transition that is not actually allowed.',
        ],
    ],

    'activity_log' => [
        'purpose' => 'Who changed what, and when.',
        'steps' => [
            'Filter by record, user or date.',
            'Open an entry to see the old and new values.',
        ],
        'affects' => [
            'Nothing. It is the audit trail.',
        ],
        'rules' => [
            'The log is portfolio-wide and restricted to portfolio roles — it is deliberately not scoped to one property.',
            'Entries are never edited or removed. That is the point of them.',
        ],
    ],

    'settings' => [
        'purpose' => 'The portfolio-wide answers — payment terms, late fees, the marketing levy, tax details, which modules are on.',
        'steps' => [
            'Change the setting for the whole portfolio here.',
            'Override it for one mall on the Property Overrides screen.',
            'Check Configuration Health afterwards to see what is still unset.',
        ],
        'affects' => [
            'These answers reach every property that has not overridden them, and take effect on documents raised from now on.',
            'Turning a module off hides its screens and its actions; it does not delete its records.',
        ],
        'rules' => [
            'This screen is the portfolio tier. A single mall\'s difference belongs in Property Overrides, and a single tenant\'s belongs on their lease.',
            'Tax rates are not here. A rate is a dated entry in the tax catalogue, so a rise can be entered in advance and a back-dated document keeps the rate that was in force.',
        ],
    ],

    'property_overrides' => [
        'purpose' => 'What this one mall answers differently from the portfolio.',
        'steps' => [
            'Fill in only the fields this mall genuinely differs on.',
            'Leave everything else blank.',
            'Clear a field to go back to the portfolio\'s answer.',
        ],
        'affects' => [
            'An override applies to documents raised at this property from now on.',
            'Clearing a field removes the override, which is why there is no delete action to look for.',
        ],
        'rules' => [
            'Blank means inherit, never zero. Each field shows the portfolio\'s answer as its placeholder so an empty box cannot be read as “this mall charges nothing”.',
            'This edits the property you have selected, and only that one.',
        ],
    ],

    'configuration_health' => [
        'purpose' => 'What is not set up yet, and what each gap actually breaks.',
        'steps' => [
            'Read the impact line, not just the red dot.',
            'Clear the rows that touch money and tax first.',
            'Re-check it before go-live and after any settings change.',
        ],
        'affects' => [
            'Nothing. It reads the live database and reports.',
        ],
        'rules' => [
            'This is a different question from whether the system is running. A perfectly healthy installation can bill every tenant at a floor rate because nobody classified the charge codes.',
            'It reads the database as it is now, so it cannot go stale the way a written checklist does.',
        ],
    ],

    'notification_center' => [
        'purpose' => 'Every alert you have been sent, in full — the bell is only a peek at the most recent few.',
        'steps' => [
            'Filter by type, by date, or by read and unread.',
            'Open an alert to read it in full and go to the record it is about.',
            'Mark alerts read once you have dealt with them.',
        ],
        'affects' => [
            'Nothing. Reading an alert changes nothing but whether it counts as unread.',
        ],
        'rules' => [
            'You see the alerts addressed to you and no one else\'s. There is no permission to grant here, because a notification has exactly one reader.',
            'An alert that has scrolled out of the bell is still here. That matters for the ones with a deadline in them — a contract notice that has passed, an SLA about to breach.',
        ],
    ],

    // ── Tenant portal ─────────────────────────────────────────────────────────────────────────
    // The reader here is the retailer, not the operator. A guide that told a tenant what the
    // billing run does would be answering a question they cannot act on, so these are written to
    // the tenant's own view: what this screen shows them, and what they can do about it.

    'portal_invoices' => [
        'purpose' => 'The bills the mall has issued to you, and what is still outstanding on each.',
        'steps' => [
            'Open an invoice to see what it is made up of, line by line.',
            'Download it as a PDF for your records.',
            'Pay it online, or pay the mall directly and it will appear here once recorded.',
        ],
        'affects' => [
            'A payment you make online is recorded against the invoice and its balance drops.',
            'Any credit the mall has issued you is applied automatically and shows against the invoice.',
        ],
        'rules' => [
            'You see your own invoices only.',
            'If you think an invoice is wrong, raise a billing query rather than ignoring it — the mall can put it on hold while it is looked at.',
        ],
    ],

    'budget' => [
        'purpose' => 'Set what each revenue and expense account is expected to do this year, so actuals can be read against the plan.',
        'steps' => [
            'Pick the year.',
            'Paste from your budget spreadsheet: account code and an annual amount, or code, month and amount for a month you want to set exactly.',
            'Import, then open the income statement and choose Budget as the comparison.',
        ],
        'affects' => [
            'Nothing posts and no balance changes — a budget is a plan, and no reported figure derives from it.',
            'The income statement gains a Budget comparison showing variance per account.',
        ],
        'rules' => [
            'Importing REPLACES this year\'s budget for this property. An account you leave out of the revision is removed, not left at its old figure.',
            'Revenue and expense accounts only; a balance-sheet account is refused because the income statement could never show it.',
            'An annual figure is spread evenly with the rounding remainder on December, so the twelve months sum to exactly what you typed.',
        ],
    ],

    'tax_depreciation' => [
        'purpose' => 'What income tax lets you deduct for depreciation this year — a different calculation from the one in your accounts.',
        'steps' => [
            'Pick the tax year.',
            'Read each pool: opening balance, what was added, what was sold, and the resulting deduction.',
            'Export the CSV for the tax file, or save it as a view to have it emailed each year.',
        ],
        'affects' => [
            'Nothing. This posts no entries and changes no balances — it is a schedule for the return, read off the fixed-asset register.',
            'What it depends on is each asset\'s tax pool: set that wrongly on the asset and this figure is wrong.',
        ],
        'rules' => [
            'The rates are the law (91/2005 Art. 25), not a setting: buildings 5%, intangibles 10%, computers and software 50%, everything else 25%.',
            'Most pools are POOLED and diminishing — additions join the pool and the rate applies to the whole balance, so no single asset ever reaches zero.',
            'A disposal leaves the pool at its original COST, not at book value.',
            'The difference from book depreciation is a temporary difference — it reverses over the asset\'s life, it is not a saving.',
        ],
    ],

    'opening_balances' => [
        'purpose' => 'Load the balances your books already carry, on the day you start using Atriom for real.',
        'steps' => [
            'Set the date to the day before you go live — usually the last day of the previous month.',
            'Paste your accountant\'s trial balance: account code, debit, credit, one account per line.',
            'Press Check it. Every unknown code, heading account and unbalanced total is listed at once.',
            'Create the draft, then review and post it from Journal Entries.',
        ],
        'affects' => [
            'Nothing reaches the ledger until you POST the draft — importing twice leaves two drafts side by side rather than doubling your balance sheet.',
            'Once posted, every statement, the trial balance and the GL tie-out all read from it.',
        ],
        'rules' => [
            'Leave out receivables and fixed assets — they load through their own importers, and entering them here would count them twice.',
            'Debits must equal credits. The import refuses an unbalanced trial balance rather than posting a plug.',
            'The entry is stamped to the property you are currently in.',
        ],
    ],

    'portal_company_profile' => [
        'purpose' => 'Your own contact details — where the mall sends invoices, reminders and notices.',
        'steps' => [
            'Check the phone and WhatsApp numbers are the ones you actually watch.',
            'Name a contact person if billing should reach someone other than the main line.',
            'Save. The change takes effect on the next notice the mall sends.',
        ],
        'affects' => [
            'Overdue reminders, payment receipts and mall announcements are addressed to these numbers — a stale number means you stop hearing about money you owe.',
            'The mall sees the change immediately on your tenant record, and who changed it is recorded.',
        ],
        'rules' => [
            'Only your account administrator can change these; other users on your account can see them but not edit.',
            'Your legal name, tax ID and commercial register are not editable here — they appear on invoices already issued, so they change by agreement with the mall.',
        ],
    ],

    'portal_credit_notes' => [
        'purpose' => 'Money the mall has credited back to you, and how much of each credit is still yours to use.',
        'steps' => [
            'Open a credit note to see what was credited, line by line, and why.',
            'Check the balance column: that is what is left to set against a future invoice.',
            'A credit raised against a specific invoice names that invoice; an account credit does not.',
        ],
        'affects' => [
            'An applied credit reduces the invoice it was applied to — the invoice balance you see elsewhere already includes it.',
            'A credit with a balance left is used automatically against your next bill, so it will change from Issued to Applied on its own.',
        ],
        'rules' => [
            'You see your own credit notes only, and only once the mall has issued them — a draft is not a document.',
            'Credit notes are raised by the mall. If you believe you are owed one, raise a billing query rather than waiting for it.',
        ],
    ],

    'portal_payments' => [
        'purpose' => 'Everything you have paid, and which invoices each payment settled.',
        'steps' => [
            'Check a payment has been recorded against the right invoice.',
            'Download a receipt.',
        ],
        'affects' => [
            'Nothing. This is a record of what has already been received.',
        ],
        'rules' => [
            'A payment appears once the mall has recorded it. A bank transfer can take a day or two to show.',
            'A post-dated cheque you have lodged is not a payment until it clears, so it will not appear here before then.',
        ],
    ],

    'portal_leases' => [
        'purpose' => 'Your own lease — the terms you signed, and the signed document itself.',
        'steps' => [
            'Read your rent, term dates, escalation, deposit and any percentage-rent terms.',
            'Download your signed lease.',
        ],
        'affects' => [
            'Nothing. This screen is read-only.',
        ],
        'rules' => [
            'These are the terms as recorded by the mall. If something does not match what you signed, raise a request.',
        ],
    ],

    'portal_requests' => [
        'purpose' => 'Asking the mall for something — a repair, a complaint, an access permit, a question about a bill.',
        'steps' => [
            'Raise the request, choosing the type that fits and describing the problem.',
            'Attach photographs where they help.',
            'Follow its progress here, and reply when the mall asks you something.',
        ],
        'affects' => [
            'The request reaches the department that handles it, with a target response time based on its urgency.',
            'You are notified as it moves, in the portal and on your phone.',
        ],
        'rules' => [
            'A closed request cannot be re-opened. If the problem comes back, raise a new one — that keeps the history of each occurrence separate.',
            'Only users on your account marked as administrators can raise requests; the rest can read them.',
        ],
    ],

    'portal_sales' => [
        'purpose' => 'Declaring what you sold, where your lease includes rent based on turnover.',
        'steps' => [
            'Upload your sales report for the period.',
            'Submit it before the deadline in your lease.',
            'The mall reviews the report and records the figure.',
        ],
        'affects' => [
            'Your declared sales decide whether any percentage rent is due above your breakpoint.',
            'A period you do not declare is estimated by the mall so billing can continue — an estimate is not a figure you stood behind, so declaring is in your interest.',
        ],
        'rules' => [
            'You attach your report; the mall enters the figure from it. That is deliberate — the document is the evidence.',
            'A declaration that has been locked cannot be changed, because it has been billed on.',
            'Your sales report is confidential and is never published or served from a public link.',
        ],
    ],

    'portal_cam' => [
        'purpose' => 'Your share of the mall\'s common-area running costs, and how it was worked out.',
        'steps' => [
            'Open an allocation to see the pool it came from and the basis of your share.',
            'Compare the year-end reconciliation against what you were billed through the year.',
        ],
        'affects' => [
            'Nothing. This is the explanation behind the CAM charges on your invoices.',
        ],
        'rules' => [
            'Your share is normally your area as a proportion of the mall\'s, weighted for the days you held the space.',
            'At year end, an underpayment is billed and an overpayment is credited back to you.',
            'Where your lease has a cap or a base year, anything above it is the landlord\'s cost, not yours.',
        ],
    ],

    'portal_announcements' => [
        'purpose' => 'Mall news — everything the mall office has told the tenants of your property.',
        'steps' => [
            'Unread notices are shown in bold, with a count on the menu item.',
            'Open one to read it in full, with any image the mall attached.',
            'Notices with an end date drop off the list once it passes.',
        ],
        'affects' => [
            'Opening a notice records that your company has read it. The mall can see that, which is how "we were never told" gets settled.',
        ],
        'rules' => [
            'You see only notices sent to your company while you were an active tenant of that property.',
            'Nothing here is editable — a notice is the mall\'s record, not yours.',
        ],
    ],

    'portal_posts' => [
        'purpose' => 'Offers and events you want shown to shoppers in the mall\'s visitor app.',
        'steps' => [
            'Write the post and add its artwork.',
            'Set when the offer is valid and when it should be shown.',
            'Submit it to the mall for review.',
        ],
        'affects' => [
            'The mall reviews it before shoppers see it. Nothing you write here is published without review.',
        ],
        'rules' => [
            'Once submitted or published, a post is read-only. Withdraw it if you need to change it — otherwise the artwork behind an approved offer could be swapped for something nobody reviewed.',
            'You cannot publish. Only the mall can.',
        ],
    ],

    'portal_notifications' => [
        'purpose' => 'Everything the mall has notified you about, in full — the bell only shows the most recent few.',
        'steps' => [
            'Filter by type or date, or show only what you have not read.',
            'Open an alert to read it in full and go to what it is about.',
        ],
        'affects' => [
            'Nothing. Reading an alert changes nothing but whether it counts as unread.',
        ],
        'rules' => [
            'You see only the alerts addressed to your account.',
            'An alert that has scrolled out of the bell is still here — which matters for the ones carrying a deadline, like a sales declaration due or an invoice falling overdue.',
        ],
    ],

    'handbook' => [
        'purpose' => 'The whole system explained — how money moves, how each record lives, and what lands in the books.',
        'steps' => [
            'Start with “the whole system, one page” if the product feels too big.',
            'Read “a month in the life” to see the sequences in the order they happen.',
            'Open “every module” for the reference — what posts, what may be edited, what cannot be deleted.',
        ],
        'affects' => [
            'Nothing. It explains the system; it changes no record.',
            'It follows your language and your light or dark setting from the panel.',
            'The tables in it are generated from the code itself, so they cannot describe a system that does not exist.',
        ],
        'rules' => [
            'This screen explains the SYSTEM. The guide button on each screen explains THAT screen — use whichever question you have.',
            'Use “open in a new tab” to read a chapter properly, or to keep it beside the screen you are working in.',
        ],
    ],

];
