<?php

namespace App\Http\Controllers;

use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformLandingEnquiry;
use App\Services\Landlord\PlatformLandingPageManager;
use App\Support\PlatformLandingContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PlatformLandingController extends Controller
{
    public function __construct(private PlatformLandingPageManager $landing) {}

    public function show(Request $request): View
    {
        $this->resolveVisitorLocale($request);

        $page = $this->landing->page();
        $content = PlatformLandingContent::normalize($page->publishedRevision?->content);

        return view('platform-site.home', [
            'content' => PlatformLandingContent::localized($content, app()->getLocale()),
            'rawContent' => $content,
            'plans' => Plan::query()->with('features')->where('is_active', true)->orderBy('price_syp')->get(),
        ]);
    }

    public function enquire(Request $request): RedirectResponse
    {
        $this->resolveVisitorLocale($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'organisation_name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:3000'],
        ]);
        PlatformLandingEnquiry::query()->create($data + [
            'uuid' => (string) Str::uuid(),
            'locale' => app()->getLocale(),
            'ip_address' => $request->ip(),
        ]);

        return redirect()->to(route('home').'#contact')->with('landing_status', __('landing.enquiry_received'));
    }

    private function resolveVisitorLocale(Request $request): void
    {
        if (! $request->session()->get('locale_user_selected')) {
            app()->setLocale($request->getPreferredLanguage(['ar', 'en']) ?: config('app.locale'));
        }
    }
}
