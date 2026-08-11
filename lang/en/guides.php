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

];
