<?php

/**
 * "Ask Atriom" — the in-app question box.
 *
 * Every key here has an Arabic twin in `lang/ar/admin/assistant.php`, and
 * `AssistantIsBilingualConformanceTest` proves it with `fallback: false` — `Lang::has()` falls back
 * to English by default, so a parity check written the obvious way passes for every key that exists
 * in English only, which is the whole failure it is meant to catch.
 */
return [
    'assistant' => [
        'nav_label' => 'Ask Atriom',
        'page_title' => 'Ask Atriom',
        'subheading' => 'Ask in Arabic or English. Answers come from this system\'s own guides — nothing leaves the server.',

        'question_label' => 'Your question',
        'question_placeholder' => 'How do I issue a credit note?',
        'question_help' => 'Either language. Type the words you would use — the wording does not have to match a screen name.',
        'ask' => 'Ask',

        'kind_screen' => 'Screen',
        'kind_report' => 'Report',
        'kind_record' => 'Record',
        'kind_doc' => 'From the handbook',
        'kind_task' => 'Do it',

        'task' => [
            'create' => 'New :thing',
            // Read ONLY as a tie-break (AnswerQuestionService::looksLikeCreating), never scored:
            // as scoring terms these tied all 61 tasks on any question containing a common verb.
            'verbs' => 'create add new make raise issue generate register enter record log file',
            'required_fields' => 'The form requires: :fields.',
            'optional_fields' => 'It also offers: :fields.',
            'and_more' => 'and :count more.',
        ],

        'steps' => 'How it is done',
        'affects' => 'What this changes elsewhere',
        'rules' => 'Rules worth knowing',

        'open_screen' => 'Open :screen',
        'open_record' => 'Open this record',
        'read_more' => 'Read the full section',

        'chat' => [
            'title' => 'Ask Atriom',
            'subtitle' => 'Answers from this system\'s own guides',
            'subtitle_no_model' => 'No model configured — showing sources only',
            'empty' => 'Ask anything about this system, in Arabic or English.',
            'thinking' => 'Thinking…',
            'clear' => 'Clear the conversation',
            'close' => 'Close',
            'helpful' => 'This helped',
            'not_helpful' => 'This did not help',
            'no_model_answer' => 'I could not write an answer just now. The sources below are what I found.',
        ],

        'report' => [
            'empty' => 'This report has no rows for the current property and period.',
            'truncated' => 'Showing :shown of :total rows — open the report for the rest.',
        ],

        'count' => [
            // Read as INTENT only — never scored, so it cannot crowd the ranking.
            'verbs' => 'how many count number total quantity split breakdown',
            'total' => 'There are :count :label in this property.',
            'by' => 'By :column —',
            // States the count as a SHARE, never a bare number: "3 unpaid" invites the reader to
            // wonder out of how many, and the total is already in hand.
            'in_state' => ':count of :total :label are :state.',
            'not_set' => 'Not set',
        ],

        // A state named in words operators use, for the sentence the count writes back.
        'states' => [
            'unpaid' => 'still unpaid',
            'settled' => 'settled',
            'unapplied' => 'not yet applied',
            'live' => 'live',
            'ended' => 'ended',
            'empty' => 'vacant',
            'let' => 'let',
        ],

        'compare' => [
            // Intent only, never scored.
            'verbs' => 'compare comparison versus vs against previous last prior earlier trend change',
            'title' => ':a compared with :b',
            'line' => ':label — :a in :a_year, :b now (:change)',
            'new_line' => ':label — no figure before :year.',
            'gone_line' => ':label — had a figure in :year and none now.',
        ],

        // The verbs of OPERATING the mall, beyond the creation ones above.
        //
        // A question that leads with a verb is asking how to DO something, so the SCREEN that does
        // it must beat any record whose name happens to collide. Measured on the operator playbook:
        // "close the accounting period" led with the Accounting *department* and the demo
        // accounting user, and "write off a bad debt" led with the posting-map row
        // `bad_debt_expense` — with the screens that answer both sitting third and fourth.
        //
        // Deliberately SEPARATE from task.verbs, which lifts create FORMS: closing a period is an
        // act and is not the making of a new record, so one list would boost the wrong screens.
        'act_verbs' => 'close closing settle clear approve reject renew terminate cancel void refund apply allocate write writeoff off post reverse submit send deliver run process reconcile chase remind lock unlock let assign dispatch complete escalate collect pay',

        // ── Operator vocabulary ───────────────────────────────────────────────────────────
        //
        // The words people type, keyed by screen guide. NOT translations of the screen name — the
        // words somebody says out loud when they are trying to get something done. Curated the same
        // way `ReportCatalogue::keywords` is, and for the same measured reason: report questions
        // were the most accurate tier precisely because they had this and nothing else did.
        //
        // THE ONE RULE: a synonym must SELECT this screen. Two things break it, and both were
        // shipped here first and measured out:
        //
        //   * A VERB. "raise" under Invoices sent "how do I raise a new invoice" to the invoice
        //     LIST instead of the form. Verbs live in `act_verbs` and `task.verbs`, where they
        //     decide the KIND of answer and never which screen.
        //   * A UBIQUITOUS NOUN. «مستأجر» / "tenant" appears in most questions in this domain, so
        //     it discriminates nothing — it only adds noise. Listing it under Tenants sent
        //     «المتأخرات على المستأجرين» ("tenant arrears") to the tenant register instead of the
        //     AR aging report. Tenants has no entry at all for that reason; its own name is enough.
        'synonyms' => [
            'payments' => 'receipt receipts collection remittance',
            'invoices' => 'bill billing charge charges',
            'credit_notes' => 'discount rebate adjustment',
            'tenant_requests' => 'complaint complaints ticket issue fault helpdesk',
            'work_orders' => 'job repair fix breakdown callout',
            'utility_meters' => 'reading readings meter meters consumption electricity water usage submeter',
            'purchase_requests' => 'purchase requisition procurement buying',
            'deposits' => 'security deposit guarantee',
            'accounting_periods' => 'closing lock the books',
            'leases' => 'contract agreement renewal termination holdover grace rent free',
            'units' => 'shop space store premises',
            'payrolls' => 'salary salaries wages payslip',
            'custodies' => 'petty cash float imprest',
            'vendor_bills' => 'supplier payable creditor',
            'violations' => 'fine penalty breach infringement house rules',
            'rentable_items' => 'parking bay kiosk storage signage',
            'sales_declarations' => 'turnover declaration percentage',
            'service_plans' => 'preventive ppm planned maintenance',
            'work_permits' => 'permit safety clearance hot work',
            'announcements' => 'notice circular broadcast',
            'cam' => 'service charge common recovery apportionment',
        ],

        'answer_heading' => 'Short answer',
        'answer_caveat' => 'Written from the sources below. Check them before acting on a figure.',

        'no_answer_heading' => 'No answer for that one',
        'review' => [
            'nav_label' => 'Assistant questions',
            'page_title' => 'What people asked',
            'subheading' => 'Every question typed into Ask Atriom, most-asked first. The ones answered by nothing are where a screen guide is missing.',
            'question' => 'Question',
            'asked' => 'Asked',
            'answered' => 'Answered',
            'answered_n_of_m' => ':n of :m',
            'never_answered' => 'Never',
            'led_to' => 'Led to',
            'last_asked' => 'Last asked',
            'unanswered_only' => 'Answered by nothing',
            'empty_heading' => 'Nobody has asked anything yet',
            'model_off' => 'No language model is configured — answers come from the guides alone, at no cost.',
            'model_unconfigured' => 'A model is switched on but has no API key, so nothing is being worded. Answers still come from the guides.',
            'model_spend' => 'Model spend this month: $:spent of $:ceiling.',
            'empty_body' => 'Questions appear here as people use Ask Atriom. The ones nothing answered are the list worth reading.',
        ],

        'no_answer_body' => 'Try the words you would use on the screen itself — a tenant, a document or a number. Your question has been recorded, and questions nothing answers are what we use to fill the gaps.',
    ],
];
