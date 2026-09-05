<?php

namespace App\Http\Controllers;

use App\Models\SystemBackup;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemBackupDownloadController extends Controller
{
    public function __invoke(SystemBackup $systemBackup): StreamedResponse
    {
        abort_unless($systemBackup->isUsable(), 404);

        $disk = Storage::disk($systemBackup->disk);
        abort_unless($disk->exists($systemBackup->file_path), 404);

        return $disk->download($systemBackup->file_path, $systemBackup->filename, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
