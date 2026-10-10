<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Lightweight Request Profiling
    |--------------------------------------------------------------------------
    |
    | Keep this disabled by default. You can profile one page by appending
    | ?perf=1 in local/dev, or enable all request logging temporarily with
    | PERFORMANCE_PROFILING=true.
    |
    */

    'enabled' => (bool) env('PERFORMANCE_PROFILING', false),

    'allow_query_string' => (bool) env('PERFORMANCE_ALLOW_QUERY_STRING', true),

    'log' => (bool) env('PERFORMANCE_LOG', true),

    'log_path' => storage_path('logs/performance.jsonl'),

    'slow_query_ms' => (float) env('PERFORMANCE_SLOW_QUERY_MS', 50),

    'report_cache_ttl_seconds' => (int) env('PERFORMANCE_REPORT_CACHE_TTL_SECONDS', 30),
];
