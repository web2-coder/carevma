<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CareVMA AI provider
    |--------------------------------------------------------------------------
    | The patch never writes to .env. Configure these values in your own .env.
    | OPENAI_API_KEY is required for ChatGPT-style natural-language planning.
    */
    'api_key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL', 'gpt-6.1-sol'),
    'base_url' => rtrim(env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
    'timeout' => (int) env('CAREVMA_AI_TIMEOUT', 45),

    /* Maximum rows returned by one report. This protects the live portal. */
    'max_rows' => max(50, (int) env('CAREVMA_AI_MAX_ROWS', 1000)),

    /* How many previous AI turns are supplied for conversational context. */
    'context_turns' => max(0, min(12, (int) env('CAREVMA_AI_CONTEXT_TURNS', 6))),
];
