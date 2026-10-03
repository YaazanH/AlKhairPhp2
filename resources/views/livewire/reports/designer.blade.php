<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\ReportDefinition;
use App\Services\ReportDesignerCatalog;
use App\Services\ReportDesignerQueryService;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    use AuthorizesPermissions;

    public ?int $editingId = null;

    public bool $editorOpen = false;

    public bool $readOnly = false;

    public string $name = '';

    public string $description = '';

    public string $dataSource = '';

    public array $selectedFields = [];

    public string $statusFilter = 'all';

    public string $searchFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $sortField = '';

    public string $sortDirection = 'asc';

    public array $previewResult = [];

    public function mount(): void
    {
        $this->authorizePermission('report-designer.view');
        $this->dataSource = $this->defaultSource();
        $this->selectedFields = app(ReportDesignerCatalog::class)->defaultFields($this->dataSource);
    }

    public function with(): array
    {
        $catalog = app(ReportDesignerCatalog::class);

        return [
            'definitions' => ReportDefinition::query()
                ->with('creator:id,name,username')
                ->when(! auth()->user()?->can('report-designer.update'), fn ($query) => $query->where('created_by', auth()->id()))
                ->latest('updated_at')
                ->get(),
            'sources' => $catalog->sources(),
            'availableFields' => $catalog->fields($this->dataSource),
            'sortableFields' => $catalog->sortableFields($this->dataSource),
        ];
    }

    public function updatedDataSource(): void
    {
        $catalog = app(ReportDesignerCatalog::class);
        abort_unless(array_key_exists($this->dataSource, $catalog->sources()), 403);

        $this->selectedFields = $catalog->defaultFields($this->dataSource);
        $this->statusFilter = 'all';
        $this->searchFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->sortField = '';
        $this->previewResult = [];
        $this->resetValidation();
    }

    public function create(): void
    {
        $this->authorizePermission('report-designer.create');
        $this->resetEditor();
        $this->editorOpen = true;
    }

    public function edit(int $definitionId): void
    {
        $this->authorizePermission('report-designer.update');
        $this->loadDefinition($definitionId, false);
    }

    public function viewDefinition(int $definitionId): void
    {
        $this->authorizePermission('report-designer.view');
        $this->loadDefinition($definitionId, true);
    }

    protected function loadDefinition(int $definitionId, bool $readOnly): void
    {
        $definition = ReportDefinition::query()
            ->when(! auth()->user()?->can('report-designer.update'), fn ($query) => $query->where('created_by', auth()->id()))
            ->findOrFail($definitionId);
        abort_unless(array_key_exists($definition->data_source, app(ReportDesignerCatalog::class)->sources()), 403);
        $filters = $definition->filters ?? [];

        $this->editorOpen = true;
        $this->readOnly = $readOnly;
        $this->editingId = $definition->id;
        $this->name = $definition->name;
        $this->description = $definition->description ?? '';
        $this->dataSource = $definition->data_source;
        $this->selectedFields = $definition->selected_fields;
        $this->statusFilter = (string) ($filters['status'] ?? 'all');
        $this->searchFilter = (string) ($filters['search'] ?? '');
        $this->dateFrom = (string) ($filters['date_from'] ?? $filters['joined_from'] ?? '');
        $this->dateTo = (string) ($filters['date_to'] ?? $filters['joined_to'] ?? '');
        $this->sortField = $definition->sort_field ?? '';
        $this->sortDirection = $definition->sort_direction;
        $this->previewResult = [];
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'report-designer.update' : 'report-designer.create');
        $validated = $this->validatedDefinition(true);
        $userId = auth()->id();

        $definition = $this->editingId
            ? ReportDefinition::query()->findOrFail($this->editingId)
            : new ReportDefinition(['created_by' => $userId]);

        $definition->fill($validated + [
            'status' => ReportDefinition::STATUS_DRAFT,
            'updated_by' => $userId,
        ])->save();

        $this->editingId = $definition->id;
        session()->flash('status', __('report_designer.messages.saved'));
    }

    public function preview(): void
    {
        $this->authorizePermission('report-designer.view');
        abort_unless($this->editorOpen, 404);

        if ($this->readOnly) {
            $saved = ReportDefinition::query()
                ->when(! auth()->user()?->can('report-designer.update'), fn ($query) => $query->where('created_by', auth()->id()))
                ->findOrFail($this->editingId);
            $definition = [
                'data_source' => $saved->data_source,
                'selected_fields' => $saved->selected_fields,
                'filters' => $saved->filters ?? [],
                'sort_field' => $saved->sort_field,
                'sort_direction' => $saved->sort_direction,
            ];
        } else {
            $definition = $this->validatedDefinition(false);
        }

        $this->previewResult = app(ReportDesignerQueryService::class)->preview([
            'data_source' => $definition['data_source'],
            'selected_fields' => $definition['selected_fields'],
            'filters' => $definition['filters'],
            'sort_field' => $definition['sort_field'],
            'sort_direction' => $definition['sort_direction'],
        ], auth()->user());
    }

    public function delete(int $definitionId): void
    {
        $this->authorizePermission('report-designer.delete');
        ReportDefinition::query()->findOrFail($definitionId)->delete();

        if ($this->editingId === $definitionId) {
            $this->resetEditor();
        }

        session()->flash('status', __('report_designer.messages.deleted'));
    }

    protected function validatedDefinition(bool $requireName): array
    {
        $catalog = app(ReportDesignerCatalog::class);
        $sourceKeys = array_keys($catalog->sources());

        $validated = $this->validate([
            'name' => [$requireName ? 'required' : 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'dataSource' => ['required', Rule::in($sourceKeys)],
            'selectedFields' => ['required', 'array', 'min:1'],
            'selectedFields.*' => ['string'],
            'statusFilter' => ['required', Rule::in(['all', 'active', 'inactive'])],
            'searchFilter' => ['nullable', 'string', 'max:100'],
            'dateFrom' => ['nullable', 'date'],
            'dateTo' => ['nullable', 'date', 'after_or_equal:dateFrom'],
            'sortField' => ['nullable', 'string'],
            'sortDirection' => ['required', Rule::in(['asc', 'desc'])],
        ]);

        $fields = $catalog->validateFields($validated['dataSource'], $validated['selectedFields']);
        [$sortField, $sortDirection] = $catalog->validateSort(
            $validated['dataSource'],
            $validated['sortField'],
            $validated['sortDirection'],
        );

        return [
            'name' => trim($validated['name'] ?? ''),
            'description' => filled($validated['description']) ? trim($validated['description']) : null,
            'data_source' => $validated['dataSource'],
            'selected_fields' => $fields,
            'filters' => [
                'status' => $validated['statusFilter'],
                'search' => trim($validated['searchFilter'] ?? ''),
                'date_from' => $validated['dateFrom'] ?? '',
                'date_to' => $validated['dateTo'] ?? '',
            ],
            'sort_field' => $sortField,
            'sort_direction' => $sortDirection,
        ];
    }

    protected function resetEditor(): void
    {
        $this->editingId = null;
        $this->editorOpen = false;
        $this->readOnly = false;
        $this->name = '';
        $this->description = '';
        $this->dataSource = $this->defaultSource();
        $this->selectedFields = app(ReportDesignerCatalog::class)->defaultFields($this->dataSource);
        $this->statusFilter = 'all';
        $this->searchFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->sortField = '';
        $this->sortDirection = 'asc';
        $this->previewResult = [];
        $this->resetValidation();
    }

    protected function defaultSource(): string
    {
        $sources = app(ReportDesignerCatalog::class)->sources();

        abort_if($sources === [], 403, 'No reporting data source is enabled.');

        return (string) array_key_first($sources);
    }
}; ?>

<div class="page-stack" data-report-designer>
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('report_designer.eyebrow') }}</div>
        <div class="mt-4 flex flex-wrap items-end justify-between gap-5">
            <div>
                <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('report_designer.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('report_designer.subtitle') }}</p>
            </div>
            <a href="{{ route('reports.index') }}" class="pill-link">{{ __('report_designer.actions.back') }}</a>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="eyebrow">{{ __('report_designer.safety.eyebrow') }}</div>
        <h2 class="font-display mt-3 text-2xl text-white">{{ __('report_designer.safety.title') }}</h2>
        <p class="mt-2 max-w-4xl text-sm leading-7 text-neutral-300">{{ __('report_designer.safety.copy') }}</p>
    </section>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(18rem,0.75fr)_minmax(0,1.6fr)]">
        <section class="surface-panel p-5 lg:p-6">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <div class="eyebrow">{{ __('report_designer.saved.eyebrow') }}</div>
                    <h2 class="font-display mt-2 text-2xl text-white">{{ __('report_designer.saved.title') }}</h2>
                </div>
                @can('report-designer.create')
                    <x-add-action-button wire:click="create" :label="__('report_designer.actions.new')" />
                @endcan
            </div>

            <div class="mt-5 grid gap-3">
                @forelse ($definitions as $definition)
                    <article class="rounded-2xl border border-white/10 bg-white/[0.03] p-4" wire:key="report-definition-{{ $definition->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate font-semibold text-white">{{ $definition->name }}</div>
                                <div class="mt-1 text-xs text-neutral-400">{{ $sources[$definition->data_source]['label'] ?? $definition->data_source }} · {{ __('report_designer.statuses.draft') }}</div>
                                <div class="mt-2 text-xs text-neutral-500">{{ __('report_designer.saved.updated', ['date' => $definition->updated_at->diffForHumans()]) }}</div>
                            </div>
                            <div class="flex shrink-0 gap-2">
                                @can('report-designer.update')
                                    <button type="button" wire:click="edit({{ $definition->id }})" class="admin-icon-button" title="{{ __('report_designer.actions.edit') }}"><x-admin-action-icon name="edit" /></button>
                                @else
                                    <button type="button" wire:click="viewDefinition({{ $definition->id }})" class="admin-icon-button" title="{{ __('report_designer.actions.open') }}"><x-admin-action-icon name="open" /></button>
                                @endcan
                                @can('report-designer.delete')
                                    <button type="button" wire:click="delete({{ $definition->id }})" wire:confirm="{{ __('report_designer.actions.delete_confirm') }}" class="admin-icon-button" title="{{ __('report_designer.actions.delete') }}"><x-admin-action-icon name="delete" /></button>
                                @endcan
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-white/10 px-4 py-10 text-center text-sm leading-6 text-neutral-400">{{ __('report_designer.saved.empty') }}</div>
                @endforelse
            </div>
        </section>

        <div class="grid gap-6">
            @if (! $editorOpen)
                <section class="surface-panel p-8 text-center lg:p-12">
                    <div class="mx-auto max-w-xl">
                        <div class="eyebrow">{{ __('report_designer.builder.eyebrow') }}</div>
                        <h2 class="font-display mt-3 text-3xl text-white">{{ __('report_designer.builder.empty_title') }}</h2>
                        <p class="mt-3 text-sm leading-7 text-neutral-300">{{ __('report_designer.builder.empty_copy') }}</p>
                    </div>
                </section>
            @else
            <section class="surface-panel p-5 lg:p-6">
                <div class="eyebrow">{{ __('report_designer.builder.eyebrow') }}</div>
                <h2 class="font-display mt-2 text-2xl text-white">{{ $editingId ? __('report_designer.builder.edit_title') : __('report_designer.builder.create_title') }}</h2>
                <p class="mt-2 text-sm leading-6 text-neutral-300">{{ __('report_designer.builder.copy') }}</p>

                <div class="mt-6 grid gap-5">
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.name') }}</span>
                            <input wire:model="name" type="text" class="rounded-xl px-4 py-3" placeholder="{{ __('report_designer.form.name_placeholder') }}" @disabled($readOnly)>
                            @error('name') <span class="text-xs text-red-300">{{ $message }}</span> @enderror
                        </label>
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.source') }}</span>
                            <select wire:model.live="dataSource" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                                @foreach ($sources as $sourceKey => $source)
                                    <option value="{{ $sourceKey }}">{{ $source['label'] }}</option>
                                @endforeach
                            </select>
                            <span class="text-xs leading-5 text-neutral-400">{{ $sources[$dataSource]['description'] ?? '' }}</span>
                        </label>
                    </div>

                    <label class="grid gap-2 text-sm text-neutral-200">
                        <span>{{ __('report_designer.form.description') }}</span>
                        <textarea wire:model="description" rows="2" class="rounded-xl px-4 py-3" placeholder="{{ __('report_designer.form.description_placeholder') }}" @disabled($readOnly)></textarea>
                    </label>

                    <div>
                        <div class="text-sm font-semibold text-white">{{ __('report_designer.form.fields') }}</div>
                        <p class="mt-1 text-xs leading-5 text-neutral-400">{{ __('report_designer.form.fields_help') }}</p>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($availableFields as $fieldKey => $field)
                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-white/10 bg-white/[0.03] px-3 py-3 text-sm text-neutral-200">
                                    <input wire:model="selectedFields" type="checkbox" value="{{ $fieldKey }}" class="rounded border-white/20 bg-transparent" @disabled($readOnly)>
                                    <span>{{ $field['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('selectedFields') <span class="mt-2 block text-xs text-red-300">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.status') }}</span>
                            <select wire:model="statusFilter" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                                <option value="all">{{ __('report_designer.filter_statuses.all') }}</option>
                                <option value="active">{{ __('report_designer.filter_statuses.active') }}</option>
                                <option value="inactive">{{ __('report_designer.filter_statuses.inactive') }}</option>
                            </select>
                        </label>
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.search') }}</span>
                            <input wire:model="searchFilter" type="search" class="rounded-xl px-4 py-3" placeholder="{{ __('report_designer.form.search_placeholder') }}" @disabled($readOnly)>
                        </label>
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.date_from') }}</span>
                            <input wire:model="dateFrom" type="date" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                        </label>
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.date_to') }}</span>
                            <input wire:model="dateTo" type="date" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                        </label>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.sort') }}</span>
                            <select wire:model="sortField" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                                <option value="">{{ __('report_designer.form.default_sort') }}</option>
                                @foreach ($sortableFields as $fieldKey => $field)
                                    <option value="{{ $fieldKey }}">{{ $field['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.direction') }}</span>
                            <select wire:model="sortDirection" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                                <option value="asc">{{ __('report_designer.form.ascending') }}</option>
                                <option value="desc">{{ __('report_designer.form.descending') }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="flex flex-wrap justify-end gap-3 border-t border-white/10 pt-5">
                        <button type="button" wire:click="preview" class="pill-link">{{ __('report_designer.actions.preview') }}</button>
                        @if (! $readOnly && (($editingId && auth()->user()?->can('report-designer.update')) || (! $editingId && auth()->user()?->can('report-designer.create'))))
                            <button type="button" wire:click="save" class="button-primary">{{ __('report_designer.actions.save_draft') }}</button>
                        @endif
                    </div>
                </div>
            </section>

            @if ($previewResult !== [])
                <section class="surface-table overflow-hidden" data-report-preview>
                    <div class="admin-grid-meta">
                        <div>
                            <div class="admin-grid-meta__title">{{ __('report_designer.preview.title') }}</div>
                            <div class="mt-1 text-xs text-neutral-400">{{ __('report_designer.preview.summary', ['shown' => count($previewResult['rows']), 'total' => $previewResult['total']]) }}</div>
                        </div>
                    </div>
                    @if ($previewResult['rows'] === [])
                        <div class="px-6 py-14 text-center text-sm text-neutral-400">{{ __('report_designer.preview.empty') }}</div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead><tr>
                                    @foreach ($previewResult['columns'] as $column)
                                        <th class="px-5 py-4 text-start">{{ $column['label'] }}</th>
                                    @endforeach
                                </tr></thead>
                                <tbody>
                                    @foreach ($previewResult['rows'] as $row)
                                        <tr class="border-t border-white/5">
                                            @foreach (array_keys($previewResult['columns']) as $fieldKey)
                                                <td class="px-5 py-4 text-neutral-200">{{ filled($row[$fieldKey] ?? null) ? $row[$fieldKey] : '—' }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif
            @endif
        </div>
    </div>
</div>
