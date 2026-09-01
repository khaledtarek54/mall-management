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

        'steps' => 'How it is done',
        'affects' => 'What this changes elsewhere',
        'rules' => 'Rules worth knowing',

        'open_screen' => 'Open :screen',
        'open_record' => 'Open this record',
        'read_more' => 'Read the full section',

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
            'empty_body' => 'Questions appear here as people use Ask Atriom. The ones nothing answered are the list worth reading.',
        ],

        'no_answer_body' => 'Try the words you would use on the screen itself — a tenant, a document or a number. Your question has been recorded, and questions nothing answers are what we use to fill the gaps.',
    ],
];
