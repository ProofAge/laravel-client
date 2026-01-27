<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ProofAge API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the ProofAge API client including authentication
    | credentials and endpoint settings.
    |
    */

    'api_key' => env('PROOFAGE_API_KEY'),

    'secret_key' => env('PROOFAGE_SECRET_KEY'),

    'base_url' => env('PROOFAGE_BASE_URL', 'https://api.proofage.xyz'),

    'version' => env('PROOFAGE_VERSION', 'v1'),

    'timeout' => env('PROOFAGE_TIMEOUT', 30),

    'retry_attempts' => env('PROOFAGE_RETRY_ATTEMPTS', 3),

    'retry_delay' => env('PROOFAGE_RETRY_DELAY', 1000), // milliseconds
];
