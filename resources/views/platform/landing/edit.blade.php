<x-platform-layout title="Landing page studio">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="text-sm font-semibold text-emerald-700">Platform website</p><h1 class="text-3xl font-bold">Landing page studio</h1><p class="mt-2 max-w-2xl text-zinc-600">Edit a safe bilingual draft, preview the public page, and publish only when both languages are complete.</p></div>
        <a href="{{ route('home') }}" target="_blank" class="rounded-xl border bg-white px-4 py-2 text-sm font-semibold">Open public page ↗</a>
    </header>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-6">
            @if($canManage)
                <form method="POST" action="{{ route('platform.landing.update') }}" enctype="multipart/form-data" class="space-y-6">
                    @csrf @method('PUT')
                    <section class="rounded-3xl border bg-white p-6 shadow-sm">
                        <h2 class="text-xl font-bold">Brand and search preview</h2><p class="mb-5 mt-1 text-sm text-zinc-500">This identity belongs to the Platform website and never replaces tenant branding.</p>
                        <div class="space-y-4"><x-platform.landing.localized-field :content="$content" path="brand" label="Public brand name"/><x-platform.landing.localized-field :content="$content" path="meta_title" label="Browser and search title"/><x-platform.landing.localized-field :content="$content" path="meta_description" label="Search description" textarea/></div>
                    </section>
                    <section class="rounded-3xl border bg-white p-6 shadow-sm">
                        <h2 class="text-xl font-bold">Hero</h2><p class="mb-5 mt-1 text-sm text-zinc-500">The first message visitors see. Keep it focused on the result the Platform creates.</p>
                        <div class="space-y-4"><x-platform.landing.localized-field :content="$content" path="hero_eyebrow" label="Eyebrow"/><x-platform.landing.localized-field :content="$content" path="hero_title" label="Main headline" textarea/><x-platform.landing.localized-field :content="$content" path="hero_body" label="Supporting message" textarea/><x-platform.landing.localized-field :content="$content" path="hero_primary_label" label="Demo button"/><x-platform.landing.localized-field :content="$content" path="hero_secondary_label" label="Sign-in button"/></div>
                    </section>

                    <section class="rounded-3xl border bg-white p-6 shadow-sm">
                        <h2 class="text-xl font-bold">Page structure</h2><p class="mb-5 mt-1 text-sm text-zinc-500">Choose which prepared sections appear and their order. Every number must be between 1 and 5.</p>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">@foreach($sections as $section)<div class="rounded-2xl border p-4"><label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="enabled_sections[{{ $section }}]" value="1" @checked(old('enabled_sections.'.$section, $content['enabled_sections'][$section]))>{{ str($section)->headline() }}</label><label class="mt-3 grid gap-1 text-xs text-zinc-500">Order<input type="number" min="1" max="5" name="section_order[{{ $section }}]" value="{{ old('section_order.'.$section, array_search($section, $content['section_order'], true) + 1) }}" class="rounded-lg border px-2 py-1 text-zinc-900"></label></div>@endforeach</div>
                    </section>

                    <section class="rounded-3xl border bg-white p-6 shadow-sm">
                        <h2 class="text-xl font-bold">Feature introduction</h2><div class="mt-5 space-y-4"><x-platform.landing.localized-field :content="$content" path="features_kicker" label="Eyebrow"/><x-platform.landing.localized-field :content="$content" path="features_title" label="Heading" textarea/><x-platform.landing.localized-field :content="$content" path="features_body" label="Introduction" textarea/></div>
                        <div class="mt-6 grid gap-4 lg:grid-cols-3">@foreach($content['feature_items'] as $index => $item)<div class="rounded-2xl border bg-zinc-50 p-4"><h3 class="mb-3 font-bold">Feature {{ $index + 1 }}</h3><div class="space-y-3"><x-platform.landing.localized-field :content="$content" path="feature_items.{{ $index }}.title" label="Title"/><x-platform.landing.localized-field :content="$content" path="feature_items.{{ $index }}.body" label="Description" textarea/></div></div>@endforeach</div>
                    </section>

                    <section class="rounded-3xl border bg-white p-6 shadow-sm">
                        <h2 class="text-xl font-bold">Scrolling product story</h2><p class="mb-5 mt-1 text-sm text-zinc-500">Upload polished screenshots from the real Platform or tenant application. JPG, PNG, or WebP up to 5 MB.</p>
                        <div class="space-y-4"><x-platform.landing.localized-field :content="$content" path="showcase_kicker" label="Eyebrow"/><x-platform.landing.localized-field :content="$content" path="showcase_title" label="Heading" textarea/><x-platform.landing.localized-field :content="$content" path="showcase_body" label="Introduction" textarea/></div>
                        <div class="mt-6 grid gap-4 lg:grid-cols-3">@foreach($content['showcase_items'] as $index => $item)<div class="rounded-2xl border bg-zinc-50 p-4"><h3 class="mb-3 font-bold">Story {{ $index + 1 }}</h3><div class="space-y-3"><x-platform.landing.localized-field :content="$content" path="showcase_items.{{ $index }}.title" label="Title"/><x-platform.landing.localized-field :content="$content" path="showcase_items.{{ $index }}.body" label="Description" textarea/>@if($item['image_path'])<img src="{{ route('platform-site.media', ['path' => $item['image_path']]) }}" class="aspect-video w-full rounded-xl border object-cover" alt="">@endif<label class="grid gap-1 text-sm font-medium">{{ $item['image_path'] ? 'Replace screenshot' : 'Add screenshot' }}<input type="file" name="showcase_images[{{ $index }}]" accept="image/jpeg,image/png,image/webp" class="rounded-xl border bg-white p-2 text-sm"></label></div></div>@endforeach</div>
                    </section>

                    @foreach(['packages' => 'Packages', 'faq' => 'Frequently asked questions', 'contact' => 'Contact invitation'] as $key => $title)
                        <section class="rounded-3xl border bg-white p-6 shadow-sm"><h2 class="text-xl font-bold">{{ $title }}</h2><div class="mt-5 space-y-4"><x-platform.landing.localized-field :content="$content" path="{{ $key }}_kicker" label="Eyebrow"/><x-platform.landing.localized-field :content="$content" path="{{ $key }}_title" label="Heading" textarea/>@if($key !== 'faq')<x-platform.landing.localized-field :content="$content" path="{{ $key }}_body" label="Introduction" textarea/>@endif</div>
                            @if($key === 'faq')<div class="mt-6 space-y-4">@foreach($content['faq_items'] as $index => $item)<div class="rounded-2xl border bg-zinc-50 p-4"><h3 class="mb-3 font-bold">Question {{ $index + 1 }}</h3><div class="space-y-3"><x-platform.landing.localized-field :content="$content" path="faq_items.{{ $index }}.question" label="Question"/><x-platform.landing.localized-field :content="$content" path="faq_items.{{ $index }}.answer" label="Answer" textarea/></div></div>@endforeach</div>@endif
                        </section>
                    @endforeach
                    <div class="sticky bottom-4 z-20 flex items-center justify-between rounded-2xl border border-emerald-200 bg-white/95 p-4 shadow-xl backdrop-blur"><p class="text-sm text-zinc-600">Saving changes only updates the private draft.</p><button class="rounded-xl bg-emerald-700 px-5 py-3 font-bold text-white">Save draft</button></div>
                </form>
            @else
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900">You can review and publish this page, but your role cannot edit its draft.</div>
            @endif
        </div>

        <aside class="space-y-6">
            <section class="rounded-3xl border bg-white p-5 shadow-sm"><h2 class="font-bold">Publishing</h2><p class="mt-2 text-sm text-zinc-600">Public now: {{ $page->published_at?->format('Y-m-d H:i') ?? 'Built-in starting page' }}</p>@if($canPublish)<form method="POST" action="{{ route('platform.landing.publish') }}" class="mt-4">@csrf<button class="w-full rounded-xl bg-zinc-900 px-4 py-3 font-bold text-white">Review checks and publish draft</button></form>@endif</section>
            <section class="rounded-3xl border bg-white p-5 shadow-sm"><h2 class="font-bold">Published history</h2><div class="mt-4 space-y-3">@forelse($revisions as $revision)<div class="rounded-xl border p-3 text-sm"><div class="flex justify-between"><strong>Revision {{ $revision->revision_number }}</strong><span class="text-zinc-500">{{ $revision->published_at->format('Y-m-d') }}</span></div><p class="mt-1 text-xs text-zinc-500">{{ $revision->publisher?->name ?? 'Former Platform user' }}</p>@if($canPublish && $page->published_revision_id !== $revision->id)<form method="POST" action="{{ route('platform.landing.restore', $revision) }}" class="mt-2">@csrf<button class="text-xs font-semibold text-emerald-700">Restore and publish this version</button></form>@endif</div>@empty<p class="text-sm text-zinc-500">No custom revision has been published yet.</p>@endforelse</div></section>
        </aside>
    </div>

    <section class="rounded-3xl border bg-white p-6 shadow-sm"><div class="flex items-center justify-between"><div><h2 class="text-xl font-bold">Demo and contact requests</h2><p class="mt-1 text-sm text-zinc-500">Simple submissions from the public page for manual follow-up.</p></div><span class="rounded-full bg-emerald-50 px-3 py-1 text-sm font-bold text-emerald-800">{{ $enquiries->total() }}</span></div><div class="mt-5 overflow-x-auto"><table class="min-w-full text-sm"><thead class="border-b text-left text-zinc-500"><tr><th class="py-3 pe-4">Received</th><th class="py-3 pe-4">Contact</th><th class="py-3 pe-4">Organisation</th><th class="py-3 pe-4">Message</th><th class="py-3">Language</th></tr></thead><tbody class="divide-y">@forelse($enquiries as $enquiry)<tr><td class="py-4 pe-4 whitespace-nowrap">{{ $enquiry->created_at->format('Y-m-d H:i') }}</td><td class="py-4 pe-4"><strong>{{ $enquiry->name }}</strong><br><a class="text-emerald-700" href="mailto:{{ $enquiry->email }}">{{ $enquiry->email }}</a>@if($enquiry->phone)<br>{{ $enquiry->phone }}@endif</td><td class="py-4 pe-4">{{ $enquiry->organisation_name }}</td><td class="max-w-md py-4 pe-4 text-zinc-600">{{ $enquiry->message ?: '—' }}</td><td class="py-4 uppercase">{{ $enquiry->locale }}</td></tr>@empty<tr><td colspan="5" class="py-10 text-center text-zinc-500">No requests have arrived yet.</td></tr>@endforelse</tbody></table></div><div class="mt-4">{{ $enquiries->links() }}</div></section>
</x-platform-layout>
