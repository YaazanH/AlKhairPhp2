<x-platform-layout :title="__('platform-content.website')">
    <div class="platform-management platform-content" data-content-workspace>
        <header class="platform-management__header">
            <p class="text-sm font-semibold text-emerald-700">{{ __('platform-workspace.groups.content') }}</p>
            <h1 class="mt-2 font-bold">{{ __('platform-content.website') }}</h1><p class="mt-3 max-w-3xl text-zinc-500">{{ __('platform-content.website_intro') }}</p>
            <div class="mt-5 flex flex-wrap gap-3"><a href="{{ route('home') }}" target="_blank" rel="noopener noreferrer" class="rounded-xl border px-4 py-2 text-sm">{{ __('platform-content.open_public') }} ↗</a>@if($canManage)<a href="{{ route('platform.enquiries.index') }}" class="rounded-xl border px-4 py-2 text-sm">{{ __('platform-content.enquiries') }}</a>@endif</div>
        </header>
        <nav class="platform-request-tabs" aria-label="{{ __('platform-content.navigation') }}">
            @foreach(['draft', 'publishing', 'history'] as $tab)<a href="{{ route('platform.landing.edit', ['view' => $tab]) }}" @if($workspaceView === $tab) aria-current="page" @endif>{{ __('platform-content.'.$tab) }}</a>@endforeach
        </nav>
        <section class="platform-content-status rounded-2xl border bg-white p-5">
            <div><strong>{{ $page->publishedRevision ? __('platform-content.public_revision', ['version' => $page->publishedRevision->revision_number]) : __('platform-content.not_published') }}</strong><p class="mt-2 text-sm text-zinc-500">{{ __('platform-content.'.($hasDraftChanges ? 'draft_changes' : 'up_to_date')) }}</p></div>
            <p class="text-sm text-zinc-500">{{ __('platform-content.saved_at', ['date' => $page->updated_at->format('Y-m-d H:i')]) }}</p>
        </section>
        @if($workspaceView === 'draft')
            @if(!$canManage)<p class="rounded-xl border bg-white p-4 text-sm">{{ __('platform-content.readonly') }}</p>@endif
            <nav class="platform-content-sections" aria-label="{{ __('platform-content.draft') }}">@foreach(['identity', 'hero', 'structure', ...$sections] as $section)<a href="#website-{{ $section }}">{{ __('platform-content.sections.'.$section) }}</a>@endforeach</nav>
            @if($canManage)<form method="POST" action="{{ route('platform.landing.update') }}" enctype="multipart/form-data" class="space-y-5" data-content-draft>@csrf @method('PUT')<input type="hidden" name="workspace_form" value="website_draft">@else<div class="space-y-5">@endif
                @foreach(['identity' => ['brand', 'meta_title', 'meta_description'], 'hero' => ['hero_eyebrow', 'hero_title', 'hero_body', 'hero_primary_label', 'hero_secondary_label']] as $key => $paths)
                    <details id="website-{{ $key }}" class="platform-content-panel rounded-3xl border bg-white p-6" open>
                        <summary class="cursor-pointer text-lg font-bold">{{ __('platform-content.sections.'.$key) }}</summary>
                        @if($key === 'identity')<p class="mt-2 text-sm text-zinc-500">{{ __('platform-content.identity_help') }}</p>@endif
                        <div class="mt-5 space-y-4">@foreach($paths as $path)<x-platform.landing.localized-field :content="$content" :path="$path" :label="__('platform-content.fields.'.$path)" :textarea="in_array($path, ['meta_description', 'hero_title', 'hero_body'])" :readonly="!$canManage" />@endforeach</div>
                    </details>
                @endforeach
                <details id="website-structure" class="platform-content-panel rounded-3xl border bg-white p-6">
                    <summary class="cursor-pointer text-lg font-bold">{{ __('platform-content.sections.structure') }}</summary><p class="mt-3 text-sm text-zinc-500">{{ __('platform-content.structure_help') }}</p>
                    <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">@foreach($sections as $section)<div class="rounded-2xl border p-4"><label class="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="enabled_sections[{{ $section }}]" value="1" @checked(old('workspace_form') === 'website_draft' ? old('enabled_sections.'.$section, false) : $content['enabled_sections'][$section]) @disabled(!$canManage)>{{ __('platform-content.sections.'.$section) }}</label><label class="mt-3 grid gap-2 text-sm">{{ __('platform-content.order') }}<input type="number" min="1" max="5" required name="section_order[{{ $section }}]" value="{{ old('section_order.'.$section, array_search($section, $content['section_order'], true) + 1) }}" class="w-full rounded-xl border p-3" @readonly(!$canManage)></label></div>@endforeach</div>
                </details>
                @foreach($sections as $section)
                    <details id="website-{{ $section }}" class="platform-content-panel rounded-3xl border bg-white p-6">
                        <summary class="cursor-pointer text-lg font-bold">{{ __('platform-content.sections.'.$section) }}</summary>
                        <div class="mt-5 space-y-4">@foreach(['kicker', 'title', 'body'] as $suffix)@if($section !== 'faq' || $suffix !== 'body')<x-platform.landing.localized-field :content="$content" :path="$section.'_'.$suffix" :label="__('platform-content.fields.'.$suffix)" :textarea="$suffix !== 'kicker'" :readonly="!$canManage" />@endif@endforeach</div>
                        @if(in_array($section, ['features', 'showcase', 'faq']))
                            @php($collection = ['features' => 'feature_items', 'showcase' => 'showcase_items', 'faq' => 'faq_items'][$section])
                            <div class="mt-6 space-y-4">@foreach($content[$collection] as $index => $item)<article class="rounded-2xl border bg-zinc-50 p-5">
                                <h3 class="mb-4 font-bold">{{ __('platform-content.'.(['features' => 'feature_item', 'showcase' => 'story_item', 'faq' => 'question_item'][$section]), ['number' => $index + 1]) }}</h3>
                                <div class="space-y-4">@foreach($section === 'faq' ? ['question', 'answer'] : ['title', 'body'] as $field)<x-platform.landing.localized-field :content="$content" :path="$collection.'.'.$index.'.'.$field" :label="__('platform-content.fields.'.(in_array($field, ['title', 'body']) ? 'item_'.$field : $field))" :textarea="in_array($field, ['body', 'answer'])" :readonly="!$canManage" />@endforeach</div>
                                @if($section === 'showcase')
                                    @if($item['image_path'])<img src="{{ route('platform-site.media', ['path' => $item['image_path']]) }}" class="platform-content-image mt-5" alt="{{ $item['title'][app()->getLocale()] ?? $item['title']['en'] }}">@else<p class="mt-4 text-sm text-zinc-500">{{ __('platform-content.default_image') }}</p>@endif
                                    @if($canManage)<label class="mt-4 grid gap-2 text-sm font-semibold">{{ __('platform-content.'.($item['image_path'] ? 'replace_image' : 'add_image')) }}<input type="file" name="showcase_images[{{ $index }}]" accept="image/jpeg,image/png,image/webp" class="w-full rounded-xl border p-3"></label><p class="mt-2 text-sm text-zinc-500">{{ __('platform-content.image_help') }}</p>@endif
                                @endif
                            </article>@endforeach</div>
                        @endif
                    </details>
                @endforeach
                @if($canManage)<div class="platform-content-savebar"><p class="text-sm" data-draft-status data-unsaved-message="{{ __('platform-content.unsaved') }}">{{ __('platform-content.save_help') }}</p><button class="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white">{{ __('platform-content.save') }}</button></div></form>@else</div>@endif
        @elseif($workspaceView === 'publishing')
            <section class="rounded-3xl border bg-white p-6"><h2 class="text-lg font-bold">{{ __('platform-content.preview') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('platform-content.preview_help') }}</p><div class="mt-4 flex flex-wrap gap-3">@foreach(['en' => 'English', 'ar' => 'العربية'] as $language => $label)<a href="{{ route('platform.landing.preview', ['language' => $language]) }}" target="_blank" rel="noopener noreferrer" class="rounded-xl border px-4 py-3 text-sm font-semibold">{{ $label }} ↗</a>@endforeach</div></section>
            <section class="rounded-3xl border bg-white p-6"><h2 class="text-lg font-bold">{{ __('platform-content.readiness') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('platform-content.checks_help') }}</p><p class="mt-4 font-semibold">{{ $publicationErrors === [] ? __('platform-content.ready') : __('platform-content.needs_work', ['count' => count($publicationErrors)]) }}</p>@if($publicationErrors !== [])<ul class="mt-4 list-disc space-y-2 ps-5 text-sm">@foreach($publicationErrors as $messages)<li>{{ $messages[0] }}</li>@endforeach</ul>@endif</section>
            <section class="rounded-3xl border bg-white p-6"><h2 class="text-lg font-bold">{{ __('platform-content.publishing') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('platform-content.publish_help') }}</p>@if($canPublish)<form method="POST" action="{{ route('platform.landing.publish') }}" class="mt-5">@csrf<button class="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white" @disabled($publicationErrors !== []) onclick="return confirm(@js(__('platform-content.publish_confirm')))">{{ __('platform-content.publish') }}</button></form>@else<p class="mt-4 text-sm">{{ __('platform-content.no_publish') }}</p>@endif</section>
        @else
            <section class="space-y-4"><p class="text-sm text-zinc-500">{{ __('platform-content.restore_help') }}</p>
                @forelse($revisions as $revision)<article class="rounded-3xl border bg-white p-6"><div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-bold">{{ __('platform-content.revision', ['version' => $revision->revision_number]) }}</h2>@if($page->published_revision_id === $revision->id)<span class="platform-request-status">{{ __('platform-content.current') }}</span>@endif</div><p class="mt-2 text-sm text-zinc-500">{{ __('platform-content.published_at', ['date' => $revision->published_at->format('Y-m-d H:i')]) }} · {{ $revision->publisher?->name ?? __('platform-content.former_user') }}</p><p class="mt-4 font-semibold" dir="ltr">{{ data_get($revision->content, 'hero_title.en') }}</p><p class="mt-2 font-semibold" dir="rtl">{{ data_get($revision->content, 'hero_title.ar') }}</p><div class="mt-5 flex flex-wrap items-center gap-3">@foreach(['en' => 'English', 'ar' => 'العربية'] as $language => $label)<a href="{{ route('platform.landing.preview', ['language' => $language, 'revision' => $revision->id]) }}" target="_blank" rel="noopener noreferrer" class="rounded-xl border px-4 py-2 text-sm">{{ __('platform-content.preview_revision') }} · {{ $label }}</a>@endforeach @if($canPublish && $page->published_revision_id !== $revision->id)<form method="POST" action="{{ route('platform.landing.restore', $revision) }}">@csrf<button class="rounded-xl border px-4 py-2 text-sm font-semibold" onclick="return confirm(@js(__('platform-content.restore_confirm')))">{{ __('platform-content.restore') }}</button></form>@endif</div></article>@empty<p class="rounded-3xl border bg-white p-6 text-sm text-zinc-500">{{ __('platform-content.empty_history') }}</p>@endforelse
                {{ $revisions->links() }}
            </section>
        @endif
    </div>
    <x-platform.content-editor-script />
</x-platform-layout>
