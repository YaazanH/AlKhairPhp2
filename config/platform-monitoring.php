<?php

return [
    'disk_warning_percent' => (float) env('PLATFORM_DISK_WARNING_PERCENT', 80),
    'disk_critical_percent' => (float) env('PLATFORM_DISK_CRITICAL_PERCENT', 90),
    'cpu_warning_percent' => (float) env('PLATFORM_CPU_WARNING_PERCENT', 80),
    'cpu_critical_percent' => (float) env('PLATFORM_CPU_CRITICAL_PERCENT', 90),
    'ram_warning_percent' => (float) env('PLATFORM_RAM_WARNING_PERCENT', 80),
    'ram_critical_percent' => (float) env('PLATFORM_RAM_CRITICAL_PERCENT', 90),
    'backup_warning_hours' => (int) env('PLATFORM_BACKUP_WARNING_HOURS', 26),
    'backup_critical_hours' => (int) env('PLATFORM_BACKUP_CRITICAL_HOURS', 48),
];
