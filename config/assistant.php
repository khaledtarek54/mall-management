<?php

/**
 * The assistant's optional language-model tier (phase B of docs/integrations/AI-ASSISTANT.md).
 *
 * **`none` is the default and means the system behaves exactly as it did through phase A**: the
 * question box finds screens, reports, records and handbook sections, and words nothing. Adding a
 * key is what turns spending on, which is why the cap lives here beside it rather than on the
 * Settings screen — only whoever can deploy can enable the spending at all, and a ceiling an
 * operator could raise without being able to set the key would be a control over nothing.
 */
return [

    /*
    |---------------------------------------------------------------------------
    | Driver
    |---------------------------------------------------------------------------
    | `none`      — no model. Retrieval only. Costs nothing. THE DEFAULT.
    | `anthropic` — Claude words an answer from what retrieval already found.
    */
    'driver' => env('ASSISTANT_DRIVER', 'none'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),

        /*
        | Haiku 4.5 by default, and that is a deliberate answer to a cost question rather than a
        | shrug. On this design the model does not CHOOSE anything — retrieval has already found
        | the passage — so its whole job is "answer this question from this text, in this
        | language". That is the workload where the cheapest current model is closest to the most
        | expensive one, and it is roughly a fifth of the price. Raise it here if the answers
        | disappoint; the miss list is how you would know.
        */
        'model' => env('ASSISTANT_MODEL', 'claude-haiku-4-5'),

        /*
        | A short answer is the product. This is not a chat that should ramble — the passage is on
        | screen underneath it, so the model's job is two or three sentences that answer the
        | question and stop.
        */
        'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 600),

        'timeout' => (int) env('ASSISTANT_TIMEOUT', 20),
    ],

    /*
    |---------------------------------------------------------------------------
    | The ceiling
    |---------------------------------------------------------------------------
    | Dollars per calendar month. At roughly $0.006 a question, the default is about 1,600
    | questions — far more than an office of fifteen will ask, and small enough that a loop or a
    | script cannot produce a bill worth arguing about.
    |
    | Spend is derived from the tokens recorded on `assistant_questions`, so it survives a cache
    | flush and can be audited: it is the same rows the miss list is built from.
    */
    'monthly_ceiling_usd' => (float) env('ASSISTANT_MONTHLY_CEILING_USD', 10.0),

    /*
    | Per million tokens, for the configured model. Used ONLY to derive spend against the ceiling
    | above — this is a budget estimate, never a bill. Anthropic's invoice is the bill.
    */
    'rates' => [
        'input_per_mtok' => (float) env('ASSISTANT_RATE_INPUT', 1.0),
        'output_per_mtok' => (float) env('ASSISTANT_RATE_OUTPUT', 5.0),
    ],

    /*
    | How long an answer to the same question is reused. Docs questions repeat constantly across an
    | office — six people ask "izzay a3mel credit note" in a week — and the cheapest question is
    | the one that never reaches the API.
    */
    'cache_hours' => (int) env('ASSISTANT_CACHE_HOURS', 168),
];
