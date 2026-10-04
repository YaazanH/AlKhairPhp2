<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\ReportDefinition;
use App\Services\ReportDefinitionAccess;
use App\Services\ReportDesignerCatalog;
use App\Services\ReportDesignerQueryService;
use App\Support\RoleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Role;

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

    public array $calculations = [];

    public string $groupBy = '';

    public string $presentationType = ReportDesignerCatalog::PRESENTATION_TABLE;

    public string $tableDensity = 'comfortable';

    public string $presentationMetric = 'record_count';

    public string $presentationTotalMetric = '';

    public string $presentationXMetric = '';

    public string $statusFilter = 'all';

    public string $searchFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $sortField = '';

    public string $sortDirection = 'asc';

    public array $previewResult = [];

    public ?int $placementDefinitionId = null;

    public array $placementRoleIds = [];

    public array $placementSizes = [];

    public bool $layoutEditorOpen = false;

    public ?int $layoutRoleId = null;

    public array $layoutItems = [];

    public function mount(): void
    {
        $this->authorizeDesignerViewer();
        $this->dataSource = $this->defaultSource();
        $this->selectedFields = app(ReportDesignerCatalog::class)->defaultFields($this->dataSource);
    }

    public function with(): array
    {
        $catalog = app(ReportDesignerCatalog::class);
        $sourceKeys = array_keys($catalog->sources(auth()->user()));
        $presentationTypes = $catalog->presentationTypes();
        if (array_key_exists($this->presentationType, $catalog->specializedPresentationTypes())) {
            $presentationTypes[$this->presentationType] = $catalog->specializedPresentationTypes()[$this->presentationType];
        }

        return [
            'definitions' => app(ReportDefinitionAccess::class)->scopeManageable(ReportDefinition::query(), auth()->user())
                ->with('creator:id,name,username')
                ->whereIn('data_source', $sourceKeys)
                ->latest('updated_at')
                ->get(),
            'sources' => $catalog->sources(auth()->user()),
            'availableFields' => $catalog->fields($this->dataSource),
            'sortableFields' => $catalog->sortableFields($this->dataSource),
            'statusFilters' => $catalog->statusFilters($this->dataSource),
            'calculationOperations' => $catalog->calculationOperations(),
            'calculableFields' => $catalog->calculableFields($this->dataSource),
            'groupableFields' => $catalog->groupableFields($this->dataSource),
            'presentationTypes' => $presentationTypes,
            'tableDensities' => $catalog->tableDensities(),
            'canAddCalculation' => $this->nextCalculation() !== null,
            'dashboardRoles' => auth()->user()?->can('report-dashboard-layout.manage') ? $this->availableDashboardRoles() : collect(),
        ];
    }

    public function updatedDataSource(): void
    {
        $catalog = app(ReportDesignerCatalog::class);
        abort_unless(array_key_exists($this->dataSource, $catalog->sources(auth()->user())), 403);

        $this->selectedFields = $catalog->defaultFields($this->dataSource);
        $this->calculations = [];
        $this->groupBy = '';
        $this->presentationType = ReportDesignerCatalog::PRESENTATION_TABLE;
        $this->tableDensity = 'comfortable';
        $this->presentationMetric = 'record_count';
        $this->presentationTotalMetric = '';
        $this->presentationXMetric = '';
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
        $this->authorizeDesignerViewer();
        $this->loadDefinition($definitionId, true);
    }

    protected function loadDefinition(int $definitionId, bool $readOnly): void
    {
        $definition = ReportDefinition::query()
            ->whereIn('data_source', $this->availableSourceKeys())
            ->tap(fn ($query) => app(ReportDefinitionAccess::class)->scopeManageable($query, auth()->user()))
            ->findOrFail($definitionId);
        $filters = $definition->filters ?? [];

        $this->editorOpen = true;
        $this->readOnly = $readOnly;
        $this->editingId = $definition->id;
        $this->name = $definition->name;
        $this->description = $definition->description ?? '';
        $this->dataSource = $definition->data_source;
        $this->selectedFields = $definition->selected_fields;
        $this->calculations = $definition->calculations ?? [];
        $this->groupBy = $definition->group_by ?? '';
        $this->presentationType = (string) data_get($definition->presentation, 'type', ReportDesignerCatalog::PRESENTATION_TABLE);
        $this->tableDensity = (string) data_get($definition->presentation, 'density', 'comfortable');
        $this->presentationMetric = (string) data_get($definition->presentation, 'metric', 'record_count');
        $this->presentationTotalMetric = (string) data_get($definition->presentation, 'total_metric', '');
        $this->presentationXMetric = (string) data_get($definition->presentation, 'x_metric', '');
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
            ? ReportDefinition::query()->whereIn('data_source', $this->availableSourceKeys())->findOrFail($this->editingId)
            : new ReportDefinition(['created_by' => $userId]);

        $definition->fill($validated + [
            'status' => $definition->exists ? $definition->status : ReportDefinition::STATUS_DRAFT,
            'updated_by' => $userId,
        ])->save();

        $this->editingId = $definition->id;
        session()->flash('status', __('report_designer.messages.saved'));
    }

    public function preview(): void
    {
        $this->authorizeDesignerViewer();
        abort_unless($this->editorOpen, 404);

        if ($this->readOnly) {
            $saved = ReportDefinition::query()
                ->tap(fn ($query) => app(ReportDefinitionAccess::class)->scopeManageable($query, auth()->user()))
                ->findOrFail($this->editingId);
            $definition = [
                'data_source' => $saved->data_source,
                'selected_fields' => $saved->selected_fields,
                'calculations' => $saved->calculations ?? [],
                'group_by' => $saved->group_by,
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
            'calculations' => $definition['calculations'],
            'group_by' => $definition['group_by'],
            'filters' => $definition['filters'],
            'sort_field' => $definition['sort_field'],
            'sort_direction' => $definition['sort_direction'],
        ], auth()->user());
    }

    public function delete(int $definitionId): void
    {
        $this->authorizePermission('report-designer.delete');
        ReportDefinition::query()
            ->whereIn('data_source', $this->availableSourceKeys())
            ->findOrFail($definitionId)
            ->delete();

        if ($this->editingId === $definitionId) {
            $this->resetEditor();
        }

        session()->flash('status', __('report_designer.messages.deleted'));
    }

    public function managePlacement(int $definitionId): void
    {
        $this->authorizePermission('report-dashboard-layout.manage');
        $definition = ReportDefinition::query()
            ->whereIn('data_source', $this->availableSourceKeys())
            ->with('dashboardRoles')
            ->findOrFail($definitionId);

        $this->placementDefinitionId = $definition->id;
        $this->placementRoleIds = $definition->dashboardRoles->pluck('id')->map(fn ($id) => (int) $id)->all();
        $existingSizes = $definition->dashboardRoles->mapWithKeys(
            fn ($role): array => [(int) $role->id => (string) $role->pivot->size],
        );
        $this->placementSizes = $this->availableDashboardRoles()
            ->mapWithKeys(fn ($role): array => [(int) $role->id => $existingSizes->get((int) $role->id, 'medium')])
            ->all();
        $this->resetValidation(['placementRoleIds', 'placementSizes']);
    }

    public function closePlacement(): void
    {
        $this->placementDefinitionId = null;
        $this->placementRoleIds = [];
        $this->placementSizes = [];
        $this->resetValidation(['placementRoleIds', 'placementSizes']);
    }

    public function savePlacement(): void
    {
        $this->authorizePermission('report-dashboard-layout.manage');
        $validated = $this->validate([
            'placementRoleIds' => ['array'],
            'placementRoleIds.*' => ['integer', 'distinct'],
            'placementSizes' => ['array'],
            'placementSizes.*' => ['required', Rule::in(['small', 'medium', 'wide'])],
        ]);
        $allowedRoleIds = $this->availableDashboardRoles()->pluck('id')->map(fn ($id) => (int) $id);
        $roleIds = collect($validated['placementRoleIds'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        abort_if($roleIds->diff($allowedRoleIds)->isNotEmpty(), 422);

        $definition = ReportDefinition::query()
            ->whereIn('data_source', $this->availableSourceKeys())
            ->findOrFail($this->placementDefinitionId);

        DB::transaction(function () use ($definition, $roleIds, $validated): void {
            $existingPositions = $definition->dashboardRoles()->pluck('report_dashboard_placements.position', 'roles.id');
            $placements = [];

            foreach ($roleIds as $roleId) {
                $position = $existingPositions->get($roleId);
                if ($position === null) {
                    $position = ((int) DB::table('report_dashboard_placements')->where('role_id', $roleId)->max('position')) + 1;
                }

                $placements[$roleId] = [
                    'position' => $position,
                    'size' => $validated['placementSizes'][$roleId] ?? 'medium',
                ];
            }

            $definition->dashboardRoles()->sync($placements);
            $definition->forceFill([
                'status' => $roleIds->isEmpty() ? ReportDefinition::STATUS_DRAFT : ReportDefinition::STATUS_PUBLISHED,
                'updated_by' => auth()->id(),
            ])->save();
        });

        $this->closePlacement();
        session()->flash('status', __('report_designer.messages.placement_saved'));
    }

    public function openRoleLayouts(): void
    {
        $this->authorizePermission('report-dashboard-layout.manage');
        $firstRole = $this->availableDashboardRoles()->first();

        abort_unless($firstRole, 404);

        $this->layoutEditorOpen = true;
        $this->selectLayoutRole((int) $firstRole->id);
    }

    public function selectLayoutRole(int $roleId): void
    {
        $this->authorizePermission('report-dashboard-layout.manage');
        abort_unless($this->availableDashboardRoles()->contains('id', $roleId), 404);

        $this->layoutRoleId = $roleId;
        $this->layoutItems = DB::table('report_dashboard_placements')
            ->join('report_definitions', 'report_definitions.id', '=', 'report_dashboard_placements.report_definition_id')
            ->where('report_dashboard_placements.role_id', $roleId)
            ->orderBy('report_dashboard_placements.position')
            ->orderBy('report_definitions.name')
            ->get([
                'report_definitions.id as report_id',
                'report_definitions.name',
                'report_dashboard_placements.size',
            ])
            ->map(fn ($item): array => [
                'report_id' => (int) $item->report_id,
                'name' => (string) $item->name,
                'size' => (string) $item->size,
            ])
            ->all();
        $this->resetValidation('layoutItems');
    }

    public function moveLayoutItem(int $index, string $direction): void
    {
        $this->authorizePermission('report-dashboard-layout.manage');
        abort_unless(in_array($direction, ['up', 'down'], true), 422);

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if (! isset($this->layoutItems[$index], $this->layoutItems[$target])) {
            return;
        }

        [$this->layoutItems[$index], $this->layoutItems[$target]] = [$this->layoutItems[$target], $this->layoutItems[$index]];
        $this->layoutItems = array_values($this->layoutItems);
    }

    public function removeLayoutItem(int $index): void
    {
        $this->authorizePermission('report-dashboard-layout.manage');
        abort_unless(isset($this->layoutItems[$index]), 404);

        unset($this->layoutItems[$index]);
        $this->layoutItems = array_values($this->layoutItems);
    }

    public function saveRoleLayout(): void
    {
        $this->authorizePermission('report-dashboard-layout.manage');
        abort_unless($this->layoutRoleId && $this->availableDashboardRoles()->contains('id', $this->layoutRoleId), 404);

        $validated = $this->validate([
            'layoutItems' => ['array'],
            'layoutItems.*.report_id' => ['required', 'integer', 'distinct'],
            'layoutItems.*.name' => ['required', 'string'],
            'layoutItems.*.size' => ['required', Rule::in(['small', 'medium', 'wide'])],
        ]);

        DB::transaction(function () use ($validated): void {
            $currentIds = DB::table('report_dashboard_placements')
                ->where('role_id', $this->layoutRoleId)
                ->lockForUpdate()
                ->pluck('report_definition_id')
                ->map(fn ($id) => (int) $id);
            $submittedIds = collect($validated['layoutItems'])->pluck('report_id')->map(fn ($id) => (int) $id);

            abort_if($submittedIds->diff($currentIds)->isNotEmpty(), 422);

            foreach ($validated['layoutItems'] as $index => $item) {
                DB::table('report_dashboard_placements')
                    ->where('role_id', $this->layoutRoleId)
                    ->where('report_definition_id', $item['report_id'])
                    ->update([
                        'position' => $index + 1,
                        'size' => $item['size'],
                        'updated_at' => now(),
                    ]);
            }

            $removedIds = $currentIds->diff($submittedIds)->values();
            if ($removedIds->isNotEmpty()) {
                DB::table('report_dashboard_placements')
                    ->where('role_id', $this->layoutRoleId)
                    ->whereIn('report_definition_id', $removedIds)
                    ->delete();
            }

            ReportDefinition::query()->whereIn('id', $currentIds)->get()->each(function (ReportDefinition $definition): void {
                $definition->forceFill([
                    'status' => $definition->dashboardRoles()->exists()
                        ? ReportDefinition::STATUS_PUBLISHED
                        : ReportDefinition::STATUS_DRAFT,
                    'updated_by' => auth()->id(),
                ])->save();
            });
        });

        $this->closeRoleLayouts();
        session()->flash('status', __('report_designer.messages.layout_saved'));
    }

    public function closeRoleLayouts(): void
    {
        $this->layoutEditorOpen = false;
        $this->layoutRoleId = null;
        $this->layoutItems = [];
        $this->resetValidation('layoutItems');
    }

    protected function validatedDefinition(bool $requireName): array
    {
        $catalog = app(ReportDesignerCatalog::class);
        $sourceKeys = array_keys($catalog->sources(auth()->user()));
        $statusKeys = array_keys($catalog->statusFilters(
            in_array($this->dataSource, $sourceKeys, true) ? $this->dataSource : $this->defaultSource(),
        ));

        $validated = $this->validate([
            'name' => [$requireName ? 'required' : 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'dataSource' => ['required', Rule::in($sourceKeys)],
            'selectedFields' => ['required', 'array', 'min:1'],
            'selectedFields.*' => ['string'],
            'calculations' => ['array', 'max:'.ReportDesignerCatalog::CALCULATION_LIMIT],
            'calculations.*.operation' => ['required', 'string'],
            'calculations.*.field' => ['nullable', 'string'],
            'groupBy' => ['nullable', 'string'],
            'presentationType' => ['required', Rule::in(array_keys(
                $this->mayPreserveSpecializedPresentation()
                    ? $catalog->libraryPresentationTypes()
                    : $catalog->presentationTypes(),
            ))],
            'tableDensity' => ['required', Rule::in(array_keys($catalog->tableDensities()))],
            'presentationMetric' => ['required', 'string'],
            'presentationTotalMetric' => ['nullable', 'string'],
            'presentationXMetric' => ['nullable', 'string'],
            'statusFilter' => ['required', Rule::in($statusKeys)],
            'searchFilter' => ['nullable', 'string', 'max:100'],
            'dateFrom' => ['nullable', 'date'],
            'dateTo' => ['nullable', 'date', 'after_or_equal:dateFrom'],
            'sortField' => ['nullable', 'string'],
            'sortDirection' => ['required', Rule::in(['asc', 'desc'])],
        ]);

        $fields = $catalog->validateFields($validated['dataSource'], $validated['selectedFields']);
        $calculations = $catalog->validateCalculations($validated['dataSource'], $validated['calculations']);
        $groupBy = $catalog->validateGrouping($validated['dataSource'], $validated['groupBy']);
        $presentation = $catalog->validatePresentation([
            'type' => $validated['presentationType'],
            'density' => $validated['tableDensity'],
            'metric' => $validated['presentationMetric'],
            'total_metric' => $validated['presentationTotalMetric'],
            'x_metric' => $validated['presentationXMetric'],
        ], $groupBy, $this->mayPreserveSpecializedPresentation(), $validated['dataSource'], $calculations);
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
            'calculations' => $calculations,
            'group_by' => $groupBy,
            'presentation' => $presentation,
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
        $this->calculations = [];
        $this->groupBy = '';
        $this->presentationType = ReportDesignerCatalog::PRESENTATION_TABLE;
        $this->tableDensity = 'comfortable';
        $this->presentationMetric = 'record_count';
        $this->presentationTotalMetric = '';
        $this->presentationXMetric = '';
        $this->statusFilter = 'all';
        $this->searchFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->sortField = '';
        $this->sortDirection = 'asc';
        $this->previewResult = [];
        $this->resetValidation();
    }

    protected function mayPreserveSpecializedPresentation(): bool
    {
        if (! $this->editingId || ! array_key_exists($this->presentationType, app(ReportDesignerCatalog::class)->specializedPresentationTypes())) {
            return false;
        }

        $definition = ReportDefinition::query()
            ->whereIn('data_source', $this->availableSourceKeys())
            ->tap(fn ($query) => app(ReportDefinitionAccess::class)->scopeManageable($query, auth()->user()))
            ->find($this->editingId);

        return $definition?->library_item_uuid !== null
            && data_get($definition->presentation, 'type') === $this->presentationType;
    }

    protected function defaultSource(): string
    {
        $sources = app(ReportDesignerCatalog::class)->sources(auth()->user());

        abort_if($sources === [], 403, 'No reporting data source is enabled.');

        return (string) array_key_first($sources);
    }

    protected function availableSourceKeys(): array
    {
        return array_keys(app(ReportDesignerCatalog::class)->sources(auth()->user()));
    }

    protected function authorizeDesignerViewer(): void
    {
        abort_unless(
            auth()->user()?->can('report-designer.view') || auth()->user()?->can('report-dashboard-layout.manage'),
            403,
        );
    }

    protected function availableDashboardRoles()
    {
        return RoleRegistry::sortCollection(
            Role::query()
                ->where('guard_name', 'web')
                ->whereNotIn('name', [RoleRegistry::PARENT, RoleRegistry::STUDENT])
                ->get(),
        );
    }

    public function addCalculation(): void
    {
        abort_if($this->readOnly || ! $this->editorOpen, 403);

        if ($calculation = $this->nextCalculation()) {
            $this->calculations[] = $calculation;
        }
    }

    public function removeCalculation(int $index): void
    {
        abort_if($this->readOnly || ! $this->editorOpen, 403);
        unset($this->calculations[$index]);
        $this->calculations = array_values($this->calculations);
        $this->resetValidation('calculations');
    }

    public function updatedCalculations(mixed $value, string $key): void
    {
        if (str_ends_with($key, '.operation') && $value === 'count') {
            $index = (int) str($key)->before('.')->toString();
            $this->calculations[$index]['field'] = '';
        }

        $this->previewResult = [];
    }

    public function updatedGroupBy(): void
    {
        if (blank($this->groupBy)) {
            $this->presentationType = ReportDesignerCatalog::PRESENTATION_TABLE;
        }

        $this->previewResult = [];
        $this->resetValidation('groupBy');
    }

    protected function nextCalculation(): ?array
    {
        if (count($this->calculations) >= ReportDesignerCatalog::CALCULATION_LIMIT) {
            return null;
        }

        $candidates = collect([['operation' => 'count', 'field' => '']]);
        foreach (array_keys(app(ReportDesignerCatalog::class)->calculableFields($this->dataSource)) as $field) {
            foreach (['sum', 'absolute_sum', 'avg', 'min', 'max'] as $operation) {
                $candidates->push(['operation' => $operation, 'field' => $field]);
            }
        }

        $existing = collect($this->calculations)
            ->map(fn (array $calculation) => ($calculation['operation'] ?? '').':'.($calculation['field'] ?? ''))
            ->all();

        return $candidates->first(fn (array $calculation) => ! in_array($calculation['operation'].':'.$calculation['field'], $existing, true));
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
            <div class="flex flex-wrap gap-2">
                @can('report-library.install')
                    <a href="{{ route('reports.library.index') }}" class="pill-link">{{ __('report_library.title') }}</a>
                @endcan
                <a href="{{ route('reports.index') }}" class="pill-link">{{ __('report_designer.actions.back') }}</a>
            </div>
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
                <div class="flex flex-wrap justify-end gap-2">
                    @can('report-dashboard-layout.manage')
                        <button type="button" wire:click="openRoleLayouts" class="pill-link">{{ __('report_designer.actions.manage_role_layouts') }}</button>
                    @endcan
                    @can('report-designer.create')
                        <x-add-action-button wire:click="create" :label="__('report_designer.actions.new')" />
                    @endcan
                </div>
            </div>

            <div class="mt-5 grid gap-3">
                @forelse ($definitions as $definition)
                    <article class="rounded-2xl border border-white/10 bg-white/[0.03] p-4" wire:key="report-definition-{{ $definition->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate font-semibold text-white">{{ $definition->name }}</div>
                                <div class="mt-1 text-xs text-neutral-400">{{ $sources[$definition->data_source]['label'] ?? $definition->data_source }} · {{ __('report_designer.statuses.'.$definition->status) }}</div>
                                @if($definition->library_item_uuid)
                                    <div class="mt-2 inline-flex rounded-full bg-emerald-400/10 px-2.5 py-1 text-xs text-emerald-200">{{ __('report_library.labels.installed_revision', ['version' => $definition->library_revision]) }}</div>
                                @endif
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
                                @can('report-dashboard-layout.manage')
                                    <button type="button" wire:click="managePlacement({{ $definition->id }})" class="admin-icon-button" title="{{ __('report_designer.actions.manage_placement') }}"><x-admin-action-icon name="chart" /></button>
                                @endcan
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2 border-t border-white/5 pt-3">
                            <a href="{{ route('reports.designer.export.xlsx', $definition) }}" class="pill-link text-xs" data-report-export-xlsx>{{ __('report_designer.actions.export_xlsx') }}</a>
                            <a href="{{ route('reports.designer.export.pdf', $definition) }}" target="_blank" rel="noopener" class="pill-link text-xs" data-report-export-pdf>{{ __('report_designer.actions.export_pdf') }}</a>
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

                    <div class="rounded-2xl border border-white/10 bg-white/[0.025] p-4" data-report-calculations>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-white">{{ __('report_designer.form.calculations') }}</div>
                                <p class="mt-1 text-xs leading-5 text-neutral-400">{{ __('report_designer.form.calculations_help', ['count' => \App\Services\ReportDesignerCatalog::CALCULATION_LIMIT]) }}</p>
                            </div>
                            @if (! $readOnly && $canAddCalculation)
                                <button type="button" wire:click="addCalculation" class="pill-link">{{ __('report_designer.actions.add_calculation') }}</button>
                            @endif
                        </div>

                        @if ($calculations === [])
                            <div class="mt-4 rounded-xl border border-dashed border-white/10 px-4 py-5 text-center text-xs text-neutral-400">{{ __('report_designer.form.no_calculations') }}</div>
                        @else
                            <div class="mt-4 grid gap-3">
                                @foreach ($calculations as $calculationIndex => $calculation)
                                    <div class="grid gap-3 rounded-xl border border-white/10 bg-black/10 p-3 md:grid-cols-[minmax(0,0.8fr)_minmax(0,1fr)_auto]" wire:key="report-calculation-{{ $calculationIndex }}">
                                        <label class="grid gap-2 text-sm text-neutral-200">
                                            <span>{{ __('report_designer.form.calculation_operation') }}</span>
                                            <select wire:model.live="calculations.{{ $calculationIndex }}.operation" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                                                @foreach ($calculationOperations as $operationKey => $operationLabel)
                                                    @if ($operationKey === 'count' || $calculableFields !== [])
                                                        <option value="{{ $operationKey }}">{{ $operationLabel }}</option>
                                                    @endif
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="grid gap-2 text-sm text-neutral-200">
                                            <span>{{ __('report_designer.form.calculation_field') }}</span>
                                            @if (($calculation['operation'] ?? 'count') === 'count')
                                                <input value="{{ __('report_designer.form.all_records') }}" class="rounded-xl px-4 py-3" disabled>
                                            @else
                                                <select wire:model="calculations.{{ $calculationIndex }}.field" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                                                    <option value="">{{ __('report_designer.form.choose_calculation_field') }}</option>
                                                    @foreach ($calculableFields as $fieldKey => $field)
                                                        <option value="{{ $fieldKey }}">{{ $field['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                        </label>
                                        @if (! $readOnly)
                                            <button type="button" wire:click="removeCalculation({{ $calculationIndex }})" class="admin-icon-button admin-icon-button--danger self-end" title="{{ __('report_designer.actions.remove_calculation') }}" aria-label="{{ __('report_designer.actions.remove_calculation') }}"><x-admin-action-icon name="delete" /></button>
                                        @endif
                                        @error("calculations.$calculationIndex") <span class="text-xs text-red-300 md:col-span-3">{{ $message }}</span> @enderror
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @error('calculations') <span class="mt-2 block text-xs text-red-300">{{ $message }}</span> @enderror
                    </div>

                    @if ($groupableFields !== [])
                        <label class="grid gap-2 rounded-2xl border border-white/10 bg-white/[0.025] p-4 text-sm text-neutral-200" data-report-grouping>
                            <span class="font-semibold text-white">{{ __('report_designer.form.grouping') }}</span>
                            <span class="text-xs leading-5 text-neutral-400">{{ __('report_designer.form.grouping_help') }}</span>
                            <select wire:model.live="groupBy" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                                <option value="">{{ __('report_designer.form.no_grouping') }}</option>
                                @foreach ($groupableFields as $fieldKey => $field)
                                    <option value="{{ $fieldKey }}">{{ $field['label'] }}</option>
                                @endforeach
                            </select>
                            @error('groupBy') <span class="text-xs text-red-300">{{ $message }}</span> @enderror
                        </label>
                    @endif

                    <div class="rounded-2xl border border-white/10 bg-white/[0.025] p-4" data-report-presentation-controls>
                        <div class="text-sm font-semibold text-white">{{ __('report_designer.presentation.title') }}</div>
                        <p class="mt-1 text-xs leading-5 text-neutral-400">{{ __('report_designer.presentation.help') }}</p>
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <label class="grid gap-2 text-sm text-neutral-200">
                                <span>{{ __('report_designer.presentation.type') }}</span>
                                <select wire:model.live="presentationType" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                                    @foreach($presentationTypes as $presentationKey => $presentationLabel)
                                        <option value="{{ $presentationKey }}" @disabled($presentationKey !== \App\Services\ReportDesignerCatalog::PRESENTATION_TABLE && blank($groupBy))>{{ $presentationLabel }}</option>
                                    @endforeach
                                </select>
                                @if(blank($groupBy))<span class="text-xs leading-5 text-amber-200">{{ __('report_designer.presentation.grouping_required_help') }}</span>@endif
                                @error('presentationType') <span class="text-xs text-red-300">{{ $message }}</span> @enderror
                            </label>
                            <label class="grid gap-2 text-sm text-neutral-200">
                                <span>{{ __('report_designer.presentation.table_density') }}</span>
                                <select wire:model.live="tableDensity" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                                    @foreach($tableDensities as $densityKey => $densityLabel)
                                        <option value="{{ $densityKey }}">{{ $densityLabel }}</option>
                                    @endforeach
                                </select>
                                <span class="text-xs leading-5 text-neutral-400">{{ __('report_designer.presentation.table_density_help') }}</span>
                                @error('tableDensity') <span class="text-xs text-red-300">{{ $message }}</span> @enderror
                            </label>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.status') }}</span>
                            <select wire:model="statusFilter" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                                @foreach ($statusFilters as $filterKey => $filterLabel)
                                    <option value="{{ $filterKey }}">{{ $filterLabel }}</option>
                                @endforeach
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
                    @if (($previewResult['calculations'] ?? []) !== [])
                        <div class="grid gap-3 border-t border-white/5 bg-white/[0.02] p-4 sm:grid-cols-2 xl:grid-cols-3" data-report-calculation-results>
                            @foreach ($previewResult['calculations'] as $calculation)
                                <div class="rounded-xl border border-white/10 bg-black/10 p-4">
                                    <div class="text-xs leading-5 text-neutral-400">{{ $calculation['label'] }}</div>
                                    <div class="mt-2 text-2xl font-semibold text-white">{{ $calculation['value'] === null ? '—' : number_format((float) $calculation['value'], is_float($calculation['value']) ? 2 : 0) }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if (($previewResult['grouping'] ?? null) !== null)
                        <div class="border-t border-white/5 p-4" data-report-grouping-results>
                            <div class="mb-3">
                                <div class="text-sm font-semibold text-white">{{ __('report_designer.grouping.title', ['field' => $previewResult['grouping']['label']]) }}</div>
                                <div class="mt-1 text-xs text-neutral-400">{{ __('report_designer.grouping.help', ['count' => $previewResult['grouping']['limit']]) }}</div>
                            </div>
                            <x-reports.group-presentation :grouping="$previewResult['grouping']" :presentation="['type' => $presentationType, 'density' => $tableDensity, 'metric' => $presentationMetric, 'total_metric' => $presentationTotalMetric, 'x_metric' => $presentationXMetric]" />
                        </div>
                    @endif
                    <x-reports.detail-table :result="$previewResult" :presentation="['type' => $presentationType, 'density' => $tableDensity]" />
                </section>
            @endif
            @endif
        </div>
    </div>

    <x-admin.modal :show="$placementDefinitionId !== null" :title="__('report_designer.placement.title')" close-method="closePlacement" max-width="2xl">
        <form wire:submit="savePlacement" class="grid gap-5">
            <p class="text-sm leading-6 text-neutral-300">{{ __('report_designer.placement.help') }}</p>

            <div class="grid gap-3">
                @foreach($dashboardRoles as $role)
                    <div class="grid gap-3 rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3 sm:grid-cols-[minmax(0,1fr)_12rem] sm:items-center">
                        <label class="flex items-center gap-3 text-sm text-neutral-200">
                            <input wire:model.live="placementRoleIds" type="checkbox" value="{{ $role->id }}" class="rounded border-white/20 bg-transparent">
                            <x-admin.role-label :name="$role->name" />
                        </label>
                        <select wire:model="placementSizes.{{ $role->id }}" class="rounded-xl px-4 py-2 text-sm" @disabled(! in_array($role->id, array_map('intval', $placementRoleIds), true)) aria-label="{{ __('report_designer.placement.size_for', ['role' => $role->name]) }}">
                            <option value="small">{{ __('report_designer.placement.sizes.small') }}</option>
                            <option value="medium">{{ __('report_designer.placement.sizes.medium') }}</option>
                            <option value="wide">{{ __('report_designer.placement.sizes.wide') }}</option>
                        </select>
                    </div>
                @endforeach
            </div>
            @error('placementRoleIds') <span class="text-xs text-red-300">{{ $message }}</span> @enderror
            @error('placementSizes.*') <span class="text-xs text-red-300">{{ $message }}</span> @enderror

            <div class="rounded-xl border border-amber-300/20 bg-amber-300/8 px-4 py-3 text-xs leading-5 text-amber-100">
                {{ __('report_designer.placement.visibility_help') }}
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closePlacement" class="pill-link">{{ __('report_designer.actions.cancel') }}</button>
                <button type="submit" class="button-primary">{{ __('report_designer.actions.apply_placement') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$layoutEditorOpen" :title="__('report_designer.layout.title')" close-method="closeRoleLayouts" max-width="3xl">
        <form wire:submit="saveRoleLayout" class="grid gap-5">
            <div>
                <p class="text-sm leading-6 text-neutral-300">{{ __('report_designer.layout.help') }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($dashboardRoles as $role)
                        <button type="button" wire:click="selectLayoutRole({{ $role->id }})" @class(['pill-link', 'border-emerald-300/40 bg-emerald-300/10 text-emerald-100' => $layoutRoleId === $role->id])>
                            <x-admin.role-label :name="$role->name" />
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="grid gap-3">
                @forelse($layoutItems as $index => $item)
                    <div class="grid gap-3 rounded-2xl border border-white/10 bg-white/[0.03] p-4 md:grid-cols-[auto_minmax(0,1fr)_11rem_auto] md:items-center" wire:key="role-layout-{{ $layoutRoleId }}-{{ $item['report_id'] }}">
                        <div class="text-xs font-semibold text-neutral-500">{{ $index + 1 }}</div>
                        <div class="min-w-0 truncate font-semibold text-white">{{ $item['name'] }}</div>
                        <select wire:model="layoutItems.{{ $index }}.size" class="rounded-xl px-3 py-2 text-sm" aria-label="{{ __('report_designer.placement.size_for_widget', ['widget' => $item['name']]) }}">
                            <option value="small">{{ __('report_designer.placement.sizes.small') }}</option>
                            <option value="medium">{{ __('report_designer.placement.sizes.medium') }}</option>
                            <option value="wide">{{ __('report_designer.placement.sizes.wide') }}</option>
                        </select>
                        <div class="flex items-center justify-end gap-2">
                            <button type="button" wire:click="moveLayoutItem({{ $index }}, 'up')" class="admin-icon-button" title="{{ __('report_designer.actions.move_up') }}" @disabled($index === 0)>↑</button>
                            <button type="button" wire:click="moveLayoutItem({{ $index }}, 'down')" class="admin-icon-button" title="{{ __('report_designer.actions.move_down') }}" @disabled($index === count($layoutItems) - 1)>↓</button>
                            <button type="button" wire:click="removeLayoutItem({{ $index }})" class="admin-icon-button admin-icon-button--danger" title="{{ __('report_designer.actions.remove_from_layout') }}"><x-admin-action-icon name="delete" /></button>
                        </div>
                    </div>
                @empty
                    <div class="rounded-2xl border border-dashed border-white/10 px-4 py-10 text-center text-sm leading-6 text-neutral-400">{{ __('report_designer.layout.empty') }}</div>
                @endforelse
            </div>
            @error('layoutItems.*') <span class="text-xs text-red-300">{{ $message }}</span> @enderror

            <div class="rounded-xl border border-amber-300/20 bg-amber-300/8 px-4 py-3 text-xs leading-5 text-amber-100">
                {{ __('report_designer.layout.remove_help') }}
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeRoleLayouts" class="pill-link">{{ __('report_designer.actions.cancel') }}</button>
                <button type="submit" class="button-primary">{{ __('report_designer.actions.save_layout') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>
