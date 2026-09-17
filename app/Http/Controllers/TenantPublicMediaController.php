<?php

namespace App\Http\Controllers;

use App\Services\Landlord\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TenantPublicMediaController extends Controller
{
    public function __invoke(Request $request, string $path): BinaryFileResponse
    {
        abort_unless(app(TenantContext::class)->hasTenant(), 404);
        abort_if($path === '' || str_contains($path, '..'), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
