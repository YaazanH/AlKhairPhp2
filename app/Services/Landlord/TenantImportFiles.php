<?php

namespace App\Services\Landlord;

use App\Models\Landlord\TenantImport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class TenantImportFiles
{
    public function path(TenantImport $operation, string $file): string
    {
        if (! Str::isUuid($operation->uuid) || ! in_array($file, ['upload.bin', 'package.enc', 'working.zip', 'converted.zip'], true)) {
            throw new RuntimeException('unsafe_path');
        }
        $directory = rtrim(config('standalone-import.directory'), '/\\').'/'.$operation->uuid;
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('staging_unavailable');
        }

        return $directory.'/'.$file;
    }

    public function locked(TenantImport $operation, callable $callback): mixed
    {
        $result = Cache::store('file')->lock('tenant-import:'.$operation->uuid, config('standalone-import.timeout') + 120)->get($callback);
        abort_if($result === false, 409);

        return $result;
    }

    public function append(TenantImport $operation, int $index, $input): void
    {
        $this->locked($operation, function () use ($operation, $index, $input): void {
            $operation->refresh();
            abort_unless($operation->status === 'uploading', 409);
            $offset = $index * config('standalone-import.chunk_bytes');
            $expected = min(config('standalone-import.chunk_bytes'), $operation->expected_bytes - $offset);
            abort_unless($expected > 0 && $index <= $operation->next_chunk, 422);
            $path = $this->path($operation, 'upload.bin');
            $stream = fopen($path, 'c+b');
            if (! $stream) {
                throw new RuntimeException('staging_unavailable');
            }
            try {
                if ($index < $operation->next_chunk) {
                    fseek($stream, $offset);
                    $existing = fread($stream, $expected);
                    $incoming = stream_get_contents($input, $expected + 1);
                    abort_unless(strlen($incoming) === $expected && hash_equals(hash('sha256', $existing), hash('sha256', $incoming)), 409);

                    return;
                }
                ftruncate($stream, $operation->uploaded_bytes);
                fseek($stream, 0, SEEK_END);
                $written = stream_copy_to_stream($input, $stream, $expected + 1);
                if ($written !== $expected) {
                    ftruncate($stream, $operation->uploaded_bytes);
                    abort(422);
                }
                fflush($stream);
                $operation->update(['uploaded_bytes' => $operation->uploaded_bytes + $written, 'next_chunk' => $index + 1, 'heartbeat_at' => now()]);
            } finally {
                fclose($stream);
            }
        });
    }

    public function clear(TenantImport $operation): void
    {
        $this->clearPlaintext($operation);
        foreach (['upload.bin', 'working.zip', 'package.enc'] as $file) {
            $path = $this->path($operation, $file);
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('cleanup_failed');
            }
        }
        rmdir(dirname($path));
    }

    public function clearPlaintext(TenantImport $operation): void
    {
        foreach (['working.zip', 'converted.zip'] as $file) {
            $path = $this->path($operation, $file);
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('cleanup_failed');
            }
        }
        $directory = $this->path($operation, 'converted.zip').'.work';
        if (is_dir($directory) && ! app('files')->deleteDirectory($directory)) {
            throw new RuntimeException('cleanup_failed');
        }
    }
}
