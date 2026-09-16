<?php

return [
    'disk' => env('BACKUP_DISK', 'local'),
    'directory' => env('BACKUP_DIRECTORY', 'backups'),
    'temporary_directory' => storage_path('app/backup-tmp'),
    'encryption_chunk_size' => 1024 * 1024,
    'process_timeout_seconds' => (int) env('BACKUP_PROCESS_TIMEOUT', 3600),
    'minimum_free_space_mb' => (int) env('BACKUP_MINIMUM_FREE_SPACE_MB', 256),
    'max_upload_mb' => (int) env('BACKUP_MAX_UPLOAD_MB', 50),
    'data_roots' => [
        // One root captures both Laravel disks and any persistent import/report
        // directories added directly under storage/app in the future.
        'storage' => storage_path('app'),
    ],
    'excluded_data_directories' => [
        'backup-tmp',
        'mpdf',
        'private/livewire-tmp',
    ],
    'database_binaries' => [
        'mysqldump' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
        'mysql' => env('BACKUP_MYSQL_BINARY', 'mysql'),
        'pg_dump' => env('BACKUP_PG_DUMP_BINARY', 'pg_dump'),
        'pg_restore' => env('BACKUP_PG_RESTORE_BINARY', 'pg_restore'),
    ],
];
