<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformLandingPageRevision;
use App\Services\Landlord\PlatformLandingPageManager;
use App\Support\PlatformLandingContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PlatformLandingPageController extends Controller
{
    public function __construct(private PlatformLandingPageManager $landing) {}

    public function edit(Request $request): View
    {
        $page = $this->landing->page();
        $content = PlatformLandingContent::normalize($page->draft_content);

        return view('platform.landing.edit', [
            'page' => $page,
            'content' => $content,
            'workspaceView' => in_array($request->query('view'), ['publishing', 'history'], true) ? $request->query('view') : 'draft',
            'publicationErrors' => $this->landing->publicationErrors($content),
            'hasDraftChanges' => ! $page->publishedRevision || $content != PlatformLandingContent::normalize($page->publishedRevision->content),
            'revisions' => $page->revisions()->with('publisher')->latest('revision_number')->paginate(12)->withQueryString(),
            'canManage' => $request->user('platform')->hasPlatformPermission('manage.landing-page'),
            'canPublish' => $request->user('platform')->hasPlatformPermission('publish.landing-page'),
            'sections' => PlatformLandingContent::SECTIONS,
        ]);
    }

    public function preview(Request $request): Response
    {
        $locale = in_array($request->query('language'), ['en', 'ar'], true) ? $request->query('language') : app()->getLocale();
        app()->setLocale($locale);
        $page = $this->landing->page();
        $revision = $request->filled('revision') ? $page->revisions()->findOrFail($request->query('revision')) : null;
        $content = PlatformLandingContent::normalize($revision?->content ?? $page->draft_content);

        return response()->view('platform-site.home', [
            'content' => PlatformLandingContent::localized($content, $locale),
            'rawContent' => $content,
            'plans' => Plan::query()->with('features')->where('is_active', true)->where('is_public', true)->orderBy('price_syp')->get(),
            'preview' => true,
            'previewRevision' => $revision?->revision_number,
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'content' => ['required', 'array'],
            'section_order' => ['required', 'array'],
            'section_order.*' => ['required', 'integer', 'min:1', 'max:5'],
            'enabled_sections' => ['nullable', 'array'],
            'showcase_images' => ['nullable', 'array'],
            'showcase_images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $content = $data['content'];
        $content['section_order'] = $data['section_order'];
        $content['enabled_sections'] = $data['enabled_sections'] ?? [];
        $this->landing->saveDraft($content, $request->file('showcase_images', []), $request->user('platform'), $request->ip());

        return back()->with('status', 'Landing page draft saved. The public website has not changed yet.');
    }

    public function publish(Request $request): RedirectResponse
    {
        $revision = $this->landing->publish($request->user('platform'), $request->ip());

        return back()->with('status', "Landing page revision {$revision->revision_number} is now public.");
    }

    public function restore(Request $request, PlatformLandingPageRevision $revision): RedirectResponse
    {
        $published = $this->landing->restore($revision, $request->user('platform'), $request->ip());

        return back()->with('status', "Revision {$revision->revision_number} was restored as new revision {$published->revision_number}.");
    }
}
