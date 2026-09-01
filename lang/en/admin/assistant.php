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

        'steps' => 'How it is done',
        'affects' => 'What this changes elsewhere',
        'rules' => 'Rules worth knowing',

        'open_screen' => 'Open :screen',

        'no_answer_heading' => 'No answer for that one',
        'no_answer_body' => 'Try the words you would use on the screen itself — a tenant, a document or a number. Your question has been recorded, and questions nothing answers are what we use to fill the gaps.',
    ],
];
