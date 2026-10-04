@php
    $editing = $item->exists;
    $canManage = auth('platform')->user()->hasPlatformPermission('manage.report-library');
    $canPublish = auth('platform')->user()->hasPlatformPermission('publish.report-library');
    $storedDefinition = $editing && data_get($item->draft_definition, 'data_source') === $source ? $item->draft_definition : [];
    $selectedFields = old('selected_fields', data_get($storedDefinition, 'selected_fields', app(\App\Services\ReportDesignerCatalog::class)->defaultFields($source)));
    $selectedCalculations = old('calculations', collect(data_get($storedDefinition, 'calculations', [['operation' => 'count', 'field' => null]]))->map(fn ($calculation) => $calculation['operation'].($calculation['field'] ? ':'.$calculation['field'] : ''))->all());
@endphp
<x-platform-layout :title="$editing ? 'Edit library item' : 'Create library item'">
    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <a href="{{ route('platform.report-library.index') }}" class="text-sm font-medium text-emerald-700">&larr; Report library</a>
            <h1 class="mt-3 text-3xl font-bold">{{ $editing ? 'Edit library draft' : 'Create library item' }}</h1>
            <p class="mt-1 max-w-3xl text-zinc-500">The draft contains structure only. It never contains tenant records, files, credentials, or raw SQL.</p>
        </div>
        @if($editing && $item->published_revision_id)
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">Published revision <strong>v{{ $item->latest_version }}</strong> remains unchanged until you publish again.</div>
        @endif
    </header>

    @if($canManage)
        <form method="GET" action="{{ $editing ? route('platform.report-library.edit', $item) : route('platform.report-library.create') }}" class="rounded-3xl border bg-white p-5 shadow-sm">
            <label for="source-picker" class="text-sm font-semibold text-zinc-800">Business data source</label>
            <div class="mt-2 flex flex-col gap-3 sm:flex-row">
                <select id="source-picker" name="source" class="w-full rounded-xl border-zinc-300 sm:max-w-xl">
                    @foreach($sources as $key => $details)<option value="{{ $key }}" @selected($source === $key)>{{ $details['label'] }}</option>@endforeach
                </select>
                <button class="rounded-xl border border-zinc-300 px-4 py-2 font-medium">Load source fields</button>
            </div>
            <p class="mt-2 text-sm text-zinc-500">{{ $sources[$source]['description'] }}</p>
        </form>
    @endif

    <form method="POST" action="{{ $editing ? route('platform.report-library.update', $item) : route('platform.report-library.store') }}" class="space-y-6">
        @csrf
        @if($editing) @method('PUT') @endif
        <input type="hidden" name="data_source" value="{{ $source }}">

        <section class="grid gap-5 rounded-3xl border bg-white p-6 shadow-sm lg:grid-cols-2">
            <div>
                <label class="text-sm font-semibold" for="name-en">English name</label>
                <input id="name-en" name="name[en]" value="{{ old('name.en', $item->name['en'] ?? '') }}" required maxlength="160" class="mt-2 w-full rounded-xl border-zinc-300" @disabled(!$canManage)>
            </div>
            <div>
                <label class="text-sm font-semibold" for="name-ar">Arabic name</label>
                <input id="name-ar" name="name[ar]" value="{{ old('name.ar', $item->name['ar'] ?? '') }}" required maxlength="160" dir="rtl" class="mt-2 w-full rounded-xl border-zinc-300" @disabled(!$canManage)>
            </div>
            <div>
                <label class="text-sm font-semibold" for="description-en">English description</label>
                <textarea id="description-en" name="description[en]" rows="3" class="mt-2 w-full rounded-xl border-zinc-300" @disabled(!$canManage)>{{ old('description.en', $item->description['en'] ?? '') }}</textarea>
            </div>
            <div>
                <label class="text-sm font-semibold" for="description-ar">Arabic description</label>
                <textarea id="description-ar" name="description[ar]" rows="3" dir="rtl" class="mt-2 w-full rounded-xl border-zinc-300" @disabled(!$canManage)>{{ old('description.ar', $item->description['ar'] ?? '') }}</textarea>
            </div>
            <div>
                <label class="text-sm font-semibold" for="kind">Available as</label>
                <select id="kind" name="kind" class="mt-2 w-full rounded-xl border-zinc-300" @disabled(!$canManage)>
                    @foreach(['report' => 'Full report', 'widget' => 'Dashboard widget', 'both' => 'Report and widget'] as $value => $label)<option value="{{ $value }}" @selected(old('kind', $item->kind ?: 'both') === $value)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="rounded-2xl bg-zinc-50 p-4 text-sm text-zinc-600">
                <p class="font-semibold text-zinc-800">Compatibility</p>
                <p class="mt-1">Requires: {{ collect($sources[$source]['required_modules'])->map(fn ($module) => config('modules.definitions.'.$module.'.name', str_replace('_', ' ', $module)))->join(', ') }}</p>
                <p class="mt-1">Tenants missing these modules will not be allowed to install this item.</p>
            </div>
        </section>

        <section class="rounded-3xl border bg-white p-6 shadow-sm">
            <h2 class="text-lg font-bold">Fields and calculations</h2>
            <p class="mt-1 text-sm text-zinc-500">Choose the columns a tenant receives. Calculations always use the full permission-scoped result.</p>
            <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($fields as $key => $field)
                    <label class="flex items-center gap-3 rounded-xl border p-3 text-sm"><input type="checkbox" name="selected_fields[]" value="{{ $key }}" @checked(in_array($key, $selectedFields, true)) @disabled(!$canManage)> <span>{{ $field['label'] }}</span></label>
                @endforeach
            </div>
            <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm"><input type="checkbox" name="calculations[]" value="count" @checked(in_array('count', $selectedCalculations, true)) @disabled(!$canManage)> Matching record count</label>
                @foreach($calculableFields as $fieldKey => $field)
                    @foreach(collect($calculationOperations)->except('count') as $operation => $operationLabel)
                        @php($calculationValue = $operation.':'.$fieldKey)
                        <label class="flex items-center gap-3 rounded-xl border p-3 text-sm"><input type="checkbox" name="calculations[]" value="{{ $calculationValue }}" @checked(in_array($calculationValue, $selectedCalculations, true)) @disabled(!$canManage)> {{ $operationLabel }}: {{ $field['label'] }}</label>
                    @endforeach
                @endforeach
            </div>
            <p class="mt-3 text-xs text-zinc-500">A template can include up to five calculations.</p>
        </section>

        <section class="grid gap-5 rounded-3xl border bg-white p-6 shadow-sm md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label class="text-sm font-semibold" for="group-by">Group results</label>
                <select id="group-by" name="group_by" class="mt-2 w-full rounded-xl border-zinc-300" @disabled(!$canManage)>
                    <option value="">No grouping</option>
                    @foreach($groupableFields as $key => $field)<option value="{{ $key }}" @selected(old('group_by', data_get($storedDefinition, 'group_by')) === $key)>{{ $field['label'] }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-semibold" for="presentation-type">Presentation</label>
                <select id="presentation-type" name="presentation_type" class="mt-2 w-full rounded-xl border-zinc-300" @disabled(!$canManage)>
                    @foreach($presentationTypes as $key => $label)<option value="{{ $key }}" @selected(old('presentation_type', data_get($storedDefinition, 'presentation.type', 'table')) === $key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-semibold" for="table-density">Table spacing</label>
                <select id="table-density" name="table_density" class="mt-2 w-full rounded-xl border-zinc-300" @disabled(!$canManage)>
                    @foreach($tableDensities as $key => $label)<option value="{{ $key }}" @selected(old('table_density', data_get($storedDefinition, 'presentation.density', 'comfortable')) === $key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-semibold" for="sort-field">Default sorting</label>
                <div class="mt-2 grid grid-cols-[1fr_auto] gap-2">
                    <select id="sort-field" name="sort_field" class="w-full rounded-xl border-zinc-300" @disabled(!$canManage)><option value="">Source default</option>@foreach($sortableFields as $key => $field)<option value="{{ $key }}" @selected(old('sort_field', data_get($storedDefinition, 'sort_field')) === $key)>{{ $field['label'] }}</option>@endforeach</select>
                    <select name="sort_direction" aria-label="Sort direction" class="rounded-xl border-zinc-300" @disabled(!$canManage)><option value="asc" @selected(old('sort_direction', data_get($storedDefinition, 'sort_direction', 'asc')) === 'asc')>A-Z</option><option value="desc" @selected(old('sort_direction', data_get($storedDefinition, 'sort_direction', 'asc')) === 'desc')>Z-A</option></select>
                </div>
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            @if($canManage)<button class="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white">{{ $editing ? 'Save draft' : 'Create draft' }}</button>@endif
            @if($editing && $canPublish)
                <button type="submit" form="publish-library-item" class="rounded-xl border border-emerald-300 bg-emerald-50 px-5 py-3 font-semibold text-emerald-900" onclick="return confirm('Publish this draft as a new immutable library revision?')">Publish new revision</button>
            @endif
        </div>
    </form>

    @if($editing && $canPublish)
        <form id="publish-library-item" method="POST" action="{{ route('platform.report-library.publish', $item) }}">@csrf</form>
    @endif
</x-platform-layout>
