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
];
