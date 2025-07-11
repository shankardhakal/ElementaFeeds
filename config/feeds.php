<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Feed Processing Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains settings for the import pipeline, such as chunk size
    | for splitting large feed files into manageable batches.
    |
    */

    // Number of records per chunk (rows in CSV) before dispatching batch
    'chunk_size' => env('FEED_CHUNK_SIZE', 100),
    
    // Maximum number of concurrent imports allowed per website
    // This prevents overwhelming a single website's API with too many simultaneous imports
    'max_concurrent_imports_per_website' => env('FEED_MAX_CONCURRENT_PER_WEBSITE', 3),
    
    /*
    |--------------------------------------------------------------------------
    | Cleanup Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for connection cleanup operations including batch processing
    | and retention policies.
    |
    */
    'cleanup' => [
        'default_batch_size' => env('CLEANUP_BATCH_SIZE', 50),
        'max_batch_size' => env('CLEANUP_MAX_BATCH_SIZE', 100),
        'retry_attempts' => env('CLEANUP_RETRY_ATTEMPTS', 3),
        'timeout_seconds' => env('CLEANUP_TIMEOUT_SECONDS', 1800),
        'retention_days' => env('CLEANUP_RETENTION_DAYS', 30),
    ],
];
