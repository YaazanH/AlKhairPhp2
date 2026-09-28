<?php

namespace App\Http\Controllers;

use App\Models\WebsitePage;
use App\Services\Landlord\CurrentModuleAccess;
use App\Services\WebsiteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class WebsiteController extends Controller
{
    public function __construct(
        protected WebsiteService $website,
    ) {}

    public function home(): View|Response|RedirectResponse
    {
        if (! app(CurrentModuleAccess::class)->enabled('public_website')) {
            return redirect()->route('login');
        }

        $page = $this->website->homePage();
        $site = $this->website->siteSettings();

        if ($site['maintenance_enabled'] ?? false) {
            $title = data_get($site, 'maintenance_title.'.app()->getLocale())
                ?: data_get($site, 'maintenance_title.'.config('app.fallback_locale', 'en'))
                ?: __('site.public.maintenance.default_title');

            return response()->view('public.maintenance', [
                'navigationMenu' => $this->website->navigationMenu(),
                'navigationPages' => $this->website->navigationPages(),
                'site' => $site,
                'title' => $title,
                'metaDescription' => data_get($site, 'maintenance_message.'.app()->getLocale())
                    ?: data_get($site, 'maintenance_message.'.config('app.fallback_locale', 'en')),
            ], 503);
        }

        return view('public.home', [
            'navigationMenu' => $this->website->navigationMenu(),
            'navigationPages' => $this->website->navigationPages(),
            'page' => $page,
            'site' => $site,
            'title' => $this->website->resolveMetaTitle($page),
            'metaDescription' => $this->website->resolveMetaDescription($page),
        ]);
    }

    public function show(WebsitePage $page): View
    {
        app(CurrentModuleAccess::class)->ensure('public_website');
        abort_unless($page->is_published, 404);

        return view('public.page', [
            'navigationMenu' => $this->website->navigationMenu(),
            'navigationPages' => $this->website->navigationPages(),
            'page' => $page,
            'site' => $this->website->siteSettings(),
            'title' => $this->website->resolveMetaTitle($page),
            'metaDescription' => $this->website->resolveMetaDescription($page),
        ]);
    }
}
