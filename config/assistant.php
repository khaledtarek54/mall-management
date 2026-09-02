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
    | `none`               — no model. Retrieval only. Costs nothing. THE DEFAULT.
    | `anthropic`          — Claude. The best answers, and there is no free tier.
    | `openai_compatible`  — anything speaking /chat/completions: Google Gemini (a genuinely free
    |                        tier, no credit card), Groq, OpenRouter, a local Ollama. One driver,
    |                        because the difference between them is a base URL and a model name.
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
    | Any OpenAI-compatible provider
    |---------------------------------------------------------------------------
    | THE FREE PATH. Google's Gemini gives a key with no credit card and a daily allowance far
    | beyond what a demo or a small office will use, and it speaks this shape:
    |
    |   ASSISTANT_DRIVER=openai_compatible
    |   ASSISTANT_BASE_URL=https://generativelanguage.googleapis.com/v1beta/openai
    |   ASSISTANT_API_KEY=<from aistudio.google.com>
    |   ASSISTANT_MODEL=gemini-3.6-flash
    |
    | Groq, OpenRouter and a local Ollama differ only in those three lines. Nothing else in the
    | system changes: the same prompt, the same passages, the same ceiling.
    */
    'openai_compatible' => [
        'api_key' => env('ASSISTANT_API_KEY'),
        'base_url' => env('ASSISTANT_BASE_URL'),
        'model' => env('ASSISTANT_MODEL', 'gemini-3.6-flash'),

        /*
        | MUCH higher than the Anthropic driver's 600, and that is a measured correction rather
        | than caution.
        |
        | Gemini 3.x spends hidden THINKING tokens against this same budget: a seven-token
        | exchange reported `total_tokens: 108`, and a real question in Arabic spent ~1,200 before
        | writing a word. At 600 the thinking consumed the whole allowance and the API returned
        | HTTP 200 with an EMPTY content string — so the assistant silently stopped wording
        | answers while every other signal said it was working. That is the worst shape a failure
        | can take, and it costs nothing to avoid on a free tier.
        |
        | The visible answer stays short regardless: the prompt asks for two to four sentences.
        */
        'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 2000),

        /*
        | Longer than the Anthropic driver's 20s, for the same reason the token budget is higher:
        | Gemini 3.x thinks before it writes, and a long technical passage pushed a real question
        | past twenty seconds — which surfaced as "could not reach the model" and a silent fall back
        | to retrieval. A demo cannot tell that apart from a broken key.
        */
        'timeout' => (int) env('ASSISTANT_TIMEOUT', 45),
    ],

    /*
    |---------------------------------------------------------------------------
    | Index the DEVELOPER documentation too
    |---------------------------------------------------------------------------
    | Off by default. `docs/modules/` explains the CODE — registries, invariants, class names — so
    | quoting it to a retail manager answers a business question with an implementation. Switch it
    | on for a technical demo or for a team that IS technical, and re-run
    | `atriom:rebuild-assistant-index`.
    */
    'index_technical_docs' => (bool) env('ASSISTANT_INDEX_TECHNICAL_DOCS', false),

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
    | above — this is a budget estimate, never a bill. The provider's invoice is the bill.
    |
    | On a free tier, set both to 0: the ceiling then never bites, which is correct because there
    | is no bill to cap. The token counts are still recorded either way, so the miss list still
    | shows how much the model is being used.
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
