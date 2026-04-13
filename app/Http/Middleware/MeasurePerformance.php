<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MeasurePerformance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldProfile($request)) {
            return $next($request);
        }

        $startedAt = microtime(true);
        $queryCount = 0;
        $queryTimeMs = 0.0;
        $slowQueries = [];
        $slowQueryMs = (float) config('performance.slow_query_ms', 50);

        DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryTimeMs, &$slowQueries, $slowQueryMs): void {
            $queryCount++;
            $queryTimeMs += $query->time;

            if ($query->time >= $slowQueryMs) {
                $slowQueries[] = [
                    'time_ms' => round($query->time, 2),
                    'sql' => Str::limit(preg_replace('/\s+/', ' ', $query->sql) ?: '', 240),
                ];
            }
        });

        $response = $next($request);

        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
        $memoryMb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        $payload = [
            'time' => now()->toDateTimeString(),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => $request->route()?->getName(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'db_ms' => round($queryTimeMs, 2),
            'queries' => $queryCount,
            'memory_mb' => $memoryMb,
            'user_id' => $request->user()?->id,
            'roles' => $request->user()?->getRoleNames()->values()->all() ?? [],
            'slow_queries' => array_slice($slowQueries, 0, 5),
        ];

        $this->addHeaders($response, $payload);

        if ((bool) config('performance.log', true)) {
            $this->appendLog($payload);
        }

        if ($request->boolean('perf')) {
            $this->injectOverlay($response, $payload);
        }

        return $response;
    }

    protected function shouldProfile(Request $request): bool
    {
        if ((bool) config('performance.enabled', false)) {
            return true;
        }

        return app()->isLocal()
            && (bool) config('performance.allow_query_string', true)
            && $request->boolean('perf');
    }

    protected function addHeaders(Response $response, array $payload): void
    {
        $response->headers->set('X-Perf-Time-Ms', (string) $payload['duration_ms']);
        $response->headers->set('X-Perf-Db-Ms', (string) $payload['db_ms']);
        $response->headers->set('X-Perf-Queries', (string) $payload['queries']);
        $response->headers->set('X-Perf-Memory-Mb', (string) $payload['memory_mb']);
        $response->headers->set('Server-Timing', sprintf(
            'app;dur=%s, db;dur=%s',
            $payload['duration_ms'],
            $payload['db_ms'],
        ));
    }

    protected function appendLog(array $payload): void
    {
        $path = (string) config('performance.log_path');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    protected function injectOverlay(Response $response, array $payload): void
    {
        $contentType = (string) $response->headers->get('Content-Type');

        if (! str_contains($contentType, 'text/html') || ! method_exists($response, 'getContent')) {
            return;
        }

        $content = $response->getContent();

        if (! is_string($content) || ! str_contains($content, '</body>')) {
            return;
        }

        $route = htmlspecialchars((string) ($payload['route'] ?: $payload['path']), ENT_QUOTES, 'UTF-8');
        $slowQueryCount = count($payload['slow_queries'] ?? []);
        $overlay = sprintf(
            '<div style="position:fixed;z-index:99999;inset:auto 16px 16px auto;max-width:calc(100vw - 32px);border:1px solid rgba(255,255,255,.18);border-radius:16px;background:rgba(10,20,14,.94);color:#f8fafc;padding:12px 14px;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;box-shadow:0 18px 60px rgba(0,0,0,.35);direction:ltr;text-align:left">Perf <strong>%sms</strong> | DB <strong>%sms</strong> | Queries <strong>%d</strong> | Memory <strong>%sMB</strong> | Slow queries <strong>%d</strong><br><span style="color:#a7f3d0">%s</span></div>',
            $payload['duration_ms'],
            $payload['db_ms'],
            $payload['queries'],
            $payload['memory_mb'],
            $slowQueryCount,
            $route,
        );

        $response->setContent(str_replace('</body>', $overlay.'</body>', $content));
    }
}
