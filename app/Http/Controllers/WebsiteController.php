<?php

namespace App\Http\Controllers;

use App\Models\WebsitePage;
use App\Services\WebsiteService;
use Illuminate\Contracts\View\View;

class WebsiteController extends Controller
{
    public function __construct(
        protected WebsiteService $website,
    ) {
    }

    public function home(): View
    {
        $page = $this->website->homePage();

        return view('public.home', [
            'navigationMenu' => $this->website->navigationMenu(),
            'navigationPages' => $this->website->navigationPages(),
            'page' => $page,
            'site' => $this->website->siteSettings(),
            'title' => $this->website->resolveMetaTitle($page),
            'metaDescription' => $this->website->resolveMetaDescription($page),
        ]);
    }

    public function show(WebsitePage $page): View
    {
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
