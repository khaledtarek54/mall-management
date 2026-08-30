<?php

declare(strict_types=1);

/**
 * The public landing page at `/`.
 *
 * Every string here has an Arabic twin in `lang/ar/landing.php` with an identical key — the panel,
 * both portals and the API are Arabic-native, and a marketing page that is English-only tells an
 * Egyptian operator the product is not. `TranslationKeyConformanceTest` (test B) fails the build if
 * the two catalogues ever carry different key sets.
 *
 * Counts are NOT written here. They come from `App\Support\LandingFacts`, which asks the registries
 * that own them, so a number on this page cannot drift from the code behind it.
 */
return [

    'meta' => [
        'title' => 'Atriom — the operations platform for Egyptian retail malls',
        'description' => 'Leases, monthly billing, CAM reconciliation, percentage rent and a full double-entry general ledger — one Arabic-native platform built for how Egyptian malls actually work.',
    ],

    'nav' => [
        'platform' => 'Platform',
        'capabilities' => 'Capabilities',
        'egypt' => 'Built for Egypt',
        'engineering' => 'Engineering',
        'skip' => 'Skip to main content',
        'sign_in' => 'Sign in',
        'menu' => 'Sections',
    ],

    'hero' => [
        'eyebrow' => 'Egyptian mall operations',
        'title' => 'Every mall transaction,',
        'title_accent' => 'in one set of books.',
        'lede' => 'Atriom runs the whole commercial lifecycle of a shopping centre — the lease, the monthly billing run, the recovery of what the mall actually spent, and the double-entry ledger underneath all of it. Arabic and English, EGP, Egyptian tax, four separate surfaces for the people who use it.',
        'cta_primary' => 'Open the admin console',
        'cta_secondary' => 'See what is inside',
        'trust' => 'In live use by an operator running malls on behalf of their owners.',

        // The illustration beside the headline. It is a real worked example, not decoration: base
        // rent is VAT-exempt in the shipped catalogue and the service charge is not, so the one
        // document on screen states the rule that catches out every system written elsewhere.
        'visual' => [
            'invoice' => 'Invoice',
            'period' => 'Period',
            'base_rent' => 'Base rent',
            'service_charge' => 'Service charge',
            'vat' => 'VAT on service charge',
            'exempt' => 'exempt',
            'total' => 'Total',
            'posts_to' => 'posts to',
            'entry' => 'Journal entry',
            'debit' => 'Dr',
            'credit' => 'Cr',
            'receivable' => 'Accounts receivable',
            'rent_revenue' => 'Rental revenue',
            'service_revenue' => 'Service charge revenue',
            'vat_payable' => 'VAT payable',
            'balanced' => 'Balanced',
        ],
    ],

    'stats' => [
        'title' => 'The system by the numbers',
        'modules' => 'modules, each one switchable',
        'gl_sources' => 'document types that post to the ledger',
        'screens' => 'screens, every one explained in both languages',
        'reports' => 'catalogued reports, filtered and exportable',
        'roles' => 'roles, each confined to what it may see',
        'surfaces' => 'separate surfaces for four kinds of user',
        'note' => 'Read live from the system\'s own registries, not typed into this page.',
    ],

    'spine' => [
        'title' => 'The money spine',
        'lede' => 'Six steps, in order. Each one assumes the one before it, and every figure on the last screen can be traced back to the first.',
        'note' => 'What settles an invoice is four channels, not one — a captured payment, a credit note, a tenant credit on account, and a security deposit netted off. Every calculation of "how much of this is paid" counts all four, in one place.',
        'steps' => [
            'lease' => [
                'title' => 'The lease',
                'body' => 'Term, premises, and the dated charge schedule everything bills from — rent, service charge, marketing levy, parking. Escalation, renewal options, amendments, holdover and move-out are all modelled as what they are.',
            ],
            'billing' => [
                'title' => 'The billing run',
                'body' => 'Monthly, on the day each property chooses. A part month is prorated by the method the lease names; tax is resolved for the document\'s own date, so a rate change entered in advance never rewrites a past invoice.',
            ],
            'recovery' => [
                'title' => 'The recoveries',
                'body' => 'What the mall spent, apportioned back. Common-area expense pools with gross-up and caps, metered utilities on a dated tariff, and percentage rent computed off declared sales.',
            ],
            'collection' => [
                'title' => 'Collection',
                'body' => 'Cash, transfer, card through Paymob, or a lodged series of post-dated cheques. Late fees apply themselves, with a floor, a ceiling and an optional recurrence — all lease terms, not code.',
            ],
            'ledger' => [
                'title' => 'The ledger',
                'body' => 'Every one of those documents posts double-entry into the same chart of accounts, tagged with the property it belongs to. One registry decides what posts; nothing can be a source without being on it.',
            ],
            'statement' => [
                'title' => 'The statements',
                'body' => 'Trial balance, income statement, balance sheet, cash flow and general ledger — laid out from the chart itself. Then the owner statement and the disbursement that closes the loop back to whoever owns the mall.',
            ],
        ],
    ],

    'capabilities' => [
        'title' => 'What is inside',
        'lede' => 'Fourteen areas of work, in the order the sidebar puts them. Every optional module has a switch, and only a super administrator can move it.',
        'items' => [
            'leasing' => [
                'title' => 'Leasing',
                'body' => 'Properties, floors, units and the lettable estate. Leases with escalation, options and amendments. Unit owners who hold no lease. Parking, storage and signage. Two floor plans, a rent roll and an expiration schedule.',
            ],
            'receivables' => [
                'title' => 'Receivables',
                'body' => 'Invoices, payments and allocation, credit notes, post-dated cheques and security deposits — with a billing-run preview before anything is raised, and a collections board for what is late.',
            ],
            'recoveries' => [
                'title' => 'Recoveries & utilities',
                'body' => 'Common-area expense pools through the estimate → reconcile → true-up cycle, utility meters priced on a dated tariff, and tenant sales declarations driving percentage rent.',
            ],
            'payables' => [
                'title' => 'Payables',
                'body' => 'Vendors with contracts, commitments, change orders and compliance documents that block dispatch when they lapse. Purchase requests through an approval ladder, and costs that arrive on a calendar.',
            ],
            'owners' => [
                'title' => 'Owners',
                'body' => 'The operator-for-owner relationship as a deliverable: the periodic owner statement, the disbursement that follows it, and the request channel between the two parties.',
            ],
            'general_ledger' => [
                'title' => 'General ledger',
                'body' => 'A real double-entry ledger — chart of accounts, dated accounting periods with a close gate, journal entries, bank accounts and reconciliation, budget, and tax on both sides.',
            ],
            'reports' => [
                'title' => 'Reports',
                'body' => 'Rent roll, expirations, aging, occupancy cost, sales analytics and the five financial statements. Filtered, saved as a named view, exported and delivered on a schedule.',
            ],
            'operations' => [
                'title' => 'Operations',
                'body' => 'The tenant request board — maintenance, complaints, permits, access, billing queries — plus violations and their fines, facility zones, the approval ladder, and notes.',
            ],
            'facility' => [
                'title' => 'Facility',
                'body' => 'Planned and corrective work orders as cost objects, service plans with derived compliance, equipment, trades, permits to work, and an SLA whose penalty reaches the contractor\'s own bill.',
            ],
            'inventory_assets' => [
                'title' => 'Inventory & assets',
                'body' => 'Perpetual stock at weighted-average cost where every movement posts, and a fixed-asset register with depreciation, disposal and the Egyptian tax-depreciation twin.',
            ],
            'hr_payroll' => [
                'title' => 'HR & payroll',
                'body' => 'Employees, payroll runs on a dated ladder of statutory rates, advances and their repayment, and العهدة — cash advanced to a person and settled back.',
            ],
            'marketing' => [
                'title' => 'Marketing',
                'body' => 'The levy charged to tenants, the budget it funds and the spend register against it, mall announcements out to retailers, and the shopper-facing offers feed.',
            ],
            'setup' => [
                'title' => 'Setup',
                'body' => 'The catalogues an operator extends without a deploy: charge codes, tax codes and rates, payment rails, expense categories, trades, violation categories, document wording, holidays.',
            ],
            'administration' => [
                'title' => 'Administration',
                'body' => 'Roles and permissions, users and their property assignments, the audit trail, custom fields on five record types, settings, health checks and the in-app handbook.',
            ],
        ],
    ],

    'surfaces' => [
        'title' => 'Four surfaces, one source of truth',
        'lede' => 'Different people need different systems. They should not need different data.',
        'admin' => [
            'label' => 'For the operator and the owner',
            'title' => 'Admin console',
            'body' => 'The whole mall, one property at a time. Leases, billing, recoveries, facility, the books. Every user is confined to the properties they hold and the modules their role covers.',
            'action' => 'Open the console',
        ],
        'portal' => [
            'label' => 'For the retailer',
            'title' => 'Tenant portal',
            'body' => 'Invoices and statements, paying online, declaring monthly sales, raising a maintenance request and tracking it. Several logins per company, and only an administrator among them may write.',
            'action' => 'Open the portal',
        ],
        'vendor' => [
            'label' => 'For the contractor',
            'title' => 'Vendor portal',
            'body' => 'A contractor sees exactly the jobs dispatched to them, and can do four things: accept, update, file evidence, and quote. Marking a job finished is deliberately not one of them — that is the operator\'s decision.',
            'action' => 'Open the vendor portal',
        ],
        'api' => [
            'label' => 'For the mobile app',
            'title' => 'Tenant API',
            'body' => 'A versioned REST API behind token authentication, carrying invoices, statements, payments, notifications, requests and sales declarations — with a generated OpenAPI contract for the app team.',
            'action' => 'Documented for the app team',
        ],
    ],

    'egypt' => [
        'title' => 'Built for Egypt, not translated into it',
        'lede' => 'The things a system written elsewhere gets wrong here — and what this one does instead.',
        'items' => [
            'arabic' => [
                'title' => 'Arabic-native, both directions',
                'body' => 'Every screen, every field, every refusal and every notification exists in Arabic and English. A document is written in the language its reader reads — not its sender\'s — and operator-typed text keeps its own direction inside it.',
            ],
            'tax' => [
                'title' => 'The tax catalogue is the accountant\'s',
                'body' => 'VAT, stamp, schedule and withholding, in both directions, as dated rungs rather than columns. Which supplies are taxable is a row the accountant sets; a rise can be entered in advance and a back-dated document keeps the rate that was in force.',
            ],
            'cheques' => [
                'title' => 'Post-dated cheques',
                'body' => 'The instrument this market actually runs on. Lodge a series against a lease, watch maturity, clear or bounce — with a standing check for the tenant who is about to run out of cheques while the lease runs on.',
            ],
            'owners' => [
                'title' => 'Unit owners and صيانة',
                'body' => 'The buyer who trades from a shop he owns holds no lease, and is billed maintenance against the ownership instead. Resale is recorded; the previous owner\'s tenure stays where it belongs.',
            ],
            'custody' => [
                'title' => 'العهدة',
                'body' => 'Cash advanced to a named person and settled back against what they spent — a first-class part of the ledger, not a spreadsheet beside it.',
            ],
            'calendar' => [
                'title' => 'The working week that is actually worked',
                'body' => 'A Friday–Saturday weekend, the operator\'s own holiday register and Ramadan short days, resolved per property — so a service-level deadline is measured against the hours anyone was there to work.',
            ],
            'payroll' => [
                'title' => 'Statutory payroll on a dated ladder',
                'body' => 'Social insurance charged on the insurable wage between the published floor and ceiling, with the cap binding the employer share too, and every figure resolved for the run\'s own month.',
            ],
            'payments' => [
                'title' => 'The rails money actually moves on',
                'body' => 'Card through Paymob, bank transfer, InstaPay, cash — a catalogue the operator extends without a deploy, each rail naming the account its money lands in.',
            ],
        ],
    ],

    'engineering' => [
        'title' => 'Built to be trusted with money',
        'lede' => 'An ERP is only as good as what it refuses to do. These are the guarantees, and each one is enforced by something that fails the build rather than by a convention.',
        'items' => [
            'never_delete' => [
                'title' => 'Money records are never deletable',
                'body' => 'Not by anyone, including a super administrator. An invoice, payment, journal entry or credit note is corrected through its own workflow — cancel, void, credit note, reverse — which leaves a trail an auditor can follow.',
            ],
            'isolation' => [
                'title' => 'One property at a time',
                'body' => 'Every model is classified as property-owned or shared, every list is scoped, and every form that can set a property is guarded on the way in. A user pinned to one mall cannot read another\'s figures through any screen, filter or URL.',
            ],
            'ledger' => [
                'title' => 'One registry decides what posts',
                'body' => 'All four dispatch paths — the real-time hook, the sweep, the close gate and the drift check — derive from the same list. A new kind of money is one line; nothing can post without being on it.',
            ],
            'audit' => [
                'title' => 'The audit trail records what changed',
                'body' => 'Over a thousand operator-settable columns, by exclusion rather than by opt-in, so a field nobody remembered to register is still recorded. Stored as data and worded at read time, so one row reads correctly in either language.',
            ],
            'periods' => [
                'title' => 'A closed month stays closed',
                'body' => 'A date that would become a journal entry inside a sealed period is refused at the point of entry, not silently dropped by a background job — and a posted document inside one cannot have its money restated.',
            ],
            'gates' => [
                'title' => 'The rules check themselves',
                'body' => 'Dozens of conformance gates assert these properties across the whole codebase, and each one has been mutation-proved: the defect it names was reintroduced and the gate had to go red. A gate that cannot fail is worse than none.',
            ],
        ],
    ],

    'automation' => [
        'title' => 'What runs while nobody is looking',
        'lede' => 'Nearly forty scheduled sweeps carry the work that has to happen on a date rather than on a click — and every one of them stops when its module is switched off.',
        'items' => [
            'billing' => 'The monthly billing run, late fees, overdue sweeps and the reminders that follow them',
            'recoveries' => 'Common-area reconciliation, missing sales declarations and their estimates, meter-driven recharges',
            'leases' => 'Option windows, escalations, expiries, renewal reminders and the occupancy they leave behind',
            'facility' => 'Preventive work orders, service-level breach scans and permits left open past their window',
            'compliance' => 'Vendor and tenant document expiry, contract renewals, cheque maturity and coverage',
            'books' => 'Ledger sync, depreciation, straight-line rent, and a weekly deep reconciliation of the books',
            'housekeeping' => 'Backups with a weekly restore drill, and the pruning of everything the system generates',
        ],
    ],

    'documents' => [
        'title' => 'The documents a mall actually sends',
        'lede' => 'Thirteen kinds of PDF, one renderer, one typeface across both scripts. A document names the issuing operator from a single place, calls itself a tax invoice only when there is a registration to put on it, carries its own reference and page count on every sheet, and is watermarked when it has been voided.',
        'items' => [
            'invoice' => 'Invoice and credit note',
            'statement' => 'Tenant statement of account',
            'cam' => 'Common-area reconciliation statement',
            'owner' => 'Owner statement and disbursement advice',
            'financials' => 'The five financial statements',
            'purchase' => 'Purchase order and withholding certificate',
            'payslip' => 'Payslip',
        ],
    ],

    'cta' => [
        'title' => 'Ready when you are',
        'body' => 'Sign in to the console, or open the portal you were sent a link for.',
        'admin' => 'Admin console',
        'portal' => 'Tenant portal',
        'vendor' => 'Vendor portal',
    ],

    'footer' => [
        'tagline' => 'The operations platform for Egyptian retail malls.',
        'product' => 'Product',
        'surfaces' => 'Surfaces',
        'sign_in' => 'Sign in',
        'health' => 'System status',
        'powered_by' => 'Powered by',
        'rights' => 'All rights reserved.',
    ],
];
