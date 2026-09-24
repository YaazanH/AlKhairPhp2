<?php

namespace App\Http\Controllers;

use App\Services\Landlord\CurrentModuleAccess;
use App\Services\Landlord\TenantContext;
use App\Services\WebsiteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TenantPublicMediaController extends Controller
{
    public function __invoke(Request $request, string $path): BinaryFileResponse
    {
        abort_unless(app(TenantContext::class)->hasTenant(), 404);
        abort_if($path === '' || str_contains($path, '..'), 404);

        $access = app(CurrentModuleAccess::class);
        $normalizedPath = ltrim($path, '/');

        if (str_starts_with($normalizedPath, 'website/')) {
            $websiteEnabled = $access->enabled('public_website');
            $publicPaths = app(WebsiteService::class)->publicMediaPaths();
            $loginPaths = array_values(array_filter($publicPaths, fn (string $publicPath): bool => $publicPath === 'website/branding/logo.jpeg'
                || $publicPath === (string) data_get(app(WebsiteService::class)->siteSettings(), 'logo_path')));
            $websiteManager = $websiteEnabled && $request->user()?->can('website.manage');

            abort_unless($websiteManager || in_array($normalizedPath, $websiteEnabled ? $publicPaths : $loginPaths, true), 404);
        } elseif (! $request->user()) {
            abort(404);
        } else {
            $modulePrefixes = [
                'students/' => 'students',
                'teachers/' => 'teachers',
                'finance/' => 'finance',
                'print-templates/' => 'custom_templates',
                'id-cards/' => 'id_cards',
            ];

            foreach ($modulePrefixes as $prefix => $module) {
                if (str_starts_with($normalizedPath, $prefix)) {
                    $access->ensure($module);
                    break;
                }
            }
        }

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
