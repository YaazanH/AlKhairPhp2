<?php

return [
    // Deployment limits, not tenant plan allowances. Uploads are split into 8 MiB requests.
    'maximum_upload_bytes' => (int) env('TENANT_IMPORT_MAX_UPLOAD_BYTES', 1024 * 1024 * 1024),
    'maximum_expanded_bytes' => (int) env('TENANT_IMPORT_MAX_EXPANDED_BYTES', 8 * 1024 * 1024 * 1024),
    'maximum_entries' => (int) env('TENANT_IMPORT_MAX_ENTRIES', 50000),
    'maximum_manifest_bytes' => 32 * 1024 * 1024,
    'maximum_row_bytes' => 16 * 1024 * 1024,
    'chunk_bytes' => 8 * 1024 * 1024,
    'timeout' => (int) env('TENANT_IMPORT_TIMEOUT', 1800),
    'directory' => storage_path('app/tenant-imports'),
];
