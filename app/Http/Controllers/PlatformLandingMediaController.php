<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformLandingMediaController extends Controller
{
    public function __invoke(string $path): StreamedResponse
    {
        abort_unless(str_starts_with($path, 'platform/landing/') && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, ['Cache-Control' => 'public, max-age=604800']);
    }
}
