<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('perf:summary {--limit=20}', function () {
    $path = (string) config('performance.log_path');

    if (! File::exists($path)) {
        $this->warn('No performance log found yet. Open a page with ?perf=1 or enable PERFORMANCE_PROFILING=true.');

        return self::SUCCESS;
    }

    $rows = collect(File::lines($path))
        ->map(fn (string $line) => json_decode($line, true))
        ->filter(fn ($row) => is_array($row))
        ->sortByDesc('duration_ms')
        ->take((int) $this->option('limit'))
        ->map(fn (array $row) => [
            'ms' => $row['duration_ms'] ?? 0,
            'db_ms' => $row['db_ms'] ?? 0,
            'queries' => $row['queries'] ?? 0,
            'memory' => $row['memory_mb'] ?? 0,
            'status' => $row['status'] ?? null,
            'route' => $row['route'] ?: ($row['path'] ?? ''),
        ]);

    $this->table(['ms', 'db ms', 'queries', 'memory MB', 'status', 'route/path'], $rows->all());

    return self::SUCCESS;
})->purpose('Show the slowest locally profiled requests.');

Artisan::command('perf:clear', function () {
    $path = (string) config('performance.log_path');

    if (File::exists($path)) {
        File::delete($path);
    }

    $this->info('Performance log cleared.');

    return self::SUCCESS;
})->purpose('Clear the local performance profiling log.');
