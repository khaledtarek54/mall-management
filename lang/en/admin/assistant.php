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
