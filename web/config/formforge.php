<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI microservice (FastAPI)
    |--------------------------------------------------------------------------
    | Laravel never talks to an LLM provider directly. All prompt
    | engineering, repair and retry logic lives in the Python service;
    | Laravel calls it over REST with a shared bearer token.
    */
    'ai' => [
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),
        'token' => env('AI_SERVICE_TOKEN', ''),
        'timeout' => (int) env('AI_SERVICE_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public form protection
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'submissions_per_minute' => (int) env('FORM_SUBMISSION_RATE_LIMIT', 10),
        'ai_generations_per_hour' => (int) env('AI_GENERATION_RATE_LIMIT', 10),
    ],

    'imports' => [
        'max_size_kb' => 10240,
    ],
];
