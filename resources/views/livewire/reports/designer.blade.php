<?php

use App\Exceptions\ReportQueryTimeoutException;
use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\ReportDefinition;
use App\Models\ReportDefinitionRevision;
use App\Models\User;
use App\Services\ReportDefinitionAccess;
use App\Services\ReportDefinitionCompatibility;
use App\Services\ReportAuditService;
use App\Services\ReportConditionService;
use App\Services\ReportDesignerCatalog;
use App\Services\ReportDesignerGuidance;
use App\Services\ReportDesignerQueryService;
use App\Services\ReportRelationshipCatalog;
use App\Services\ReportVersionService;
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

    public array $relationships = [];

    public array $relationshipModes = [];

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

    public array $conditionTree = [];

    public string $sortField = '';

    public string $sortDirection = 'asc';

    public array $previewResult = [];

    public ?int $previewRoleId = null;

    public ?int $previewUserId = null;

    public ?int $placementDefinitionId = null;

    public array $placementRoleIds = [];

    public array $placementSizes = [];

    public bool $layoutEditorOpen = false;

    public ?int $layoutRoleId = null;

    public array $layoutItems = [];

    public ?int $historyDefinitionId = null;

    public function mount(): void
    {
        $this->authorizeDesignerViewer();
        $this->dataSource = $this->defaultSource();
        $this->selectedFields = app(ReportDesignerCatalog::class)->defaultFields($this->dataSource);
        $this->relationships = app(ReportRelationshipCatalog::class)->infer($this->dataSource, $this->selectedFields);
        $this->relationshipModes = app(ReportRelationshipCatalog::class)->validateModes($this->dataSource, $this->relationships, null);
        $this->conditionTree = app(ReportConditionService::class)->emptyTree();
    }

    public function with(): array
    {
        $catalog = app(ReportDesignerCatalog::class);
        $relationshipCatalog = app(ReportRelationshipCatalog::class);
        $relationshipFields = $relationshipCatalog->fields($this->dataSource, $this->relationships);
        $queryFieldKeys = $relationshipCatalog->queryFieldKeys($this->dataSource, $this->relationships);
        $canTestRole = auth()->user()?->can('report-dashboard-layout.manage') ?? false;
        $dashboardRoles = $canTestRole ? $this->availableDashboardRoles() : collect();
        $previewRole = $this->previewRoleId
            ? $dashboardRoles->firstWhere('id', $this->previewRoleId)
            : null;
        $previewUsers = $previewRole
            ? User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn ($query) => $query->whereKey($previewRole->id))
                ->orderBy('name')
                ->orderBy('username')
                ->get(['id', 'name', 'username'])
            : collect();
        $sourceKeys = array_keys($catalog->sources(auth()->user()));
        $definitions = app(ReportDefinitionAccess::class)->scopeManageable(ReportDefinition::query(), auth()->user())
            ->with('creator:id,name,username')
            ->latest('updated_at')
            ->get();
        $compatibility = app(ReportDefinitionCompatibility::class);
        $definitionCompatibility = $definitions->mapWithKeys(
            fn (ReportDefinition $definition): array => [
                $definition->id => $compatibility->inspect($definition, auth()->user()),
            ],
        );
        $definitions = $definitions
            ->filter(fn (ReportDefinition $definition): bool => $definitionCompatibility[$definition->id]['visible'])
            ->values();
        $guidance = app(ReportDesignerGuidance::class);
        $definitionGuidance = $definitions->mapWithKeys(
            fn (ReportDefinition $definition): array => [
                $definition->id => $guidance->build([
                    'data_source' => $definition->data_source,
                    'relationships' => $definition->relationships,
                    'relationship_modes' => $definition->relationship_modes,
                    'selected_fields' => $definition->selected_fields,
                    'calculations' => $definition->calculations ?? [],
                    'group_by' => $definition->group_by,
                    'presentation' => $definition->presentation ?? [],
                    'filters' => $definition->filters ?? [],
                    'sort_field' => $definition->sort_field,
                    'sort_direction' => $definition->sort_direction,
                ], auth()->user()),
            ],
        );
        $historyDefinition = $this->historyDefinitionId
            ? ReportDefinition::query()
                ->whereIn('data_source', $sourceKeys)
                ->tap(fn ($query) => app(ReportDefinitionAccess::class)->scopeManageable($query, auth()->user()))
                ->find($this->historyDefinitionId)
            : null;
        $presentationTypes = $catalog->presentationTypes();
        if (array_key_exists($this->presentationType, $catalog->specializedPresentationTypes())) {
            $presentationTypes[$this->presentationType] = $catalog->specializedPresentationTypes()[$this->presentationType];
        }

        return [
            'definitions' => $definitions,
            'definitionCompatibility' => $definitionCompatibility,
            'definitionGuidance' => $definitionGuidance,
            'sources' => $catalog->sources(auth()->user()),
            'allSources' => $catalog->librarySources(),
            'availableRelationships' => $relationshipCatalog->available($this->dataSource),
            'fieldGroups' => $relationshipCatalog->fieldGroups($this->dataSource, $this->relationships),
            'availableFields' => $relationshipFields,
            'sortableFields' => collect($catalog->sortableFields($this->dataSource))->only($queryFieldKeys)->all(),
            'statusFilters' => $catalog->statusFilters($this->dataSource),
            'calculationOperations' => $catalog->calculationOperations(),
            'calculableFields' => collect($catalog->calculableFields($this->dataSource))->only($queryFieldKeys)->all(),
            'groupableFields' => collect($catalog->groupableFields($this->dataSource))->only($queryFieldKeys)->all(),
            'conditionFields' => collect(app(ReportConditionService::class)->fields($this->dataSource))->only($queryFieldKeys)->all(),
            'presentationTypes' => $presentationTypes,
            'tableDensities' => $catalog->tableDensities(),
            'canAddCalculation' => $this->nextCalculation() !== null,
            'designGuidance' => app(ReportDesignerGuidance::class)->build([
                'data_source' => $this->dataSource,
                'selected_fields' => $this->selectedFields,
                'relationships' => $this->relationships,
                'relationship_modes' => $this->relationshipModes,
                'calculations' => $this->calculations,
                'group_by' => $this->groupBy,
                'presentation' => ['type' => $this->presentationType],
                'filters' => [
                    'status' => $this->statusFilter,
                    'search' => $this->searchFilter,
                    'date_from' => $this->dateFrom,
                    'date_to' => $this->dateTo,
                    'condition_tree' => $this->conditionTree,
                ],
                'sort_field' => $this->sortField,
                'sort_direction' => $this->sortDirection,
            ], auth()->user()),
            'dashboardRoles' => $dashboardRoles,
            'previewRole' => $previewRole,
            'previewUsers' => $previewUsers,
            'previewRoleHasPlacement' => $previewRole && $this->editingId
                ? ReportDefinition::query()
                    ->whereKey($this->editingId)
                    ->whereHas('dashboardRoles', fn ($query) => $query->whereKey($previewRole->id))
                    ->exists()
                : false,
            'historyDefinition' => $historyDefinition,
            'historyRevisions' => $historyDefinition
                ? $historyDefinition->revisions()->with('creator:id,name,username')->latest('revision_number')->get()
                : collect(),
        ];
    }

    public function updatedDataSource(): void
    {
        $catalog = app(ReportDesignerCatalog::class);
        abort_unless(array_key_exists($this->dataSource, $catalog->sources(auth()->user())), 403);

        $this->selectedFields = $catalog->defaultFields($this->dataSource);
        $this->relationships = app(ReportRelationshipCatalog::class)->infer($this->dataSource, $this->selectedFields);
        $this->relationshipModes = app(ReportRelationshipCatalog::class)->validateModes($this->dataSource, $this->relationships, null);
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
        $this->conditionTree = app(ReportConditionService::class)->emptyTree();
        $this->sortField = '';
        $this->previewResult = [];
        $this->previewRoleId = null;
        $this->previewUserId = null;
        $this->resetValidation();
    }

    public function updatedRelationships(): void
    {
        abort_if($this->readOnly || ! $this->editorOpen, 403);

        $relationshipCatalog = app(ReportRelationshipCatalog::class);
        $available = $relationshipCatalog->available($this->dataSource);
        $this->relationships = collect($this->relationships)
            ->filter(fn ($relationship): bool => array_key_exists((string) $relationship, $available))
            ->map(fn ($relationship): string => (string) $relationship)
            ->unique()
            ->values()
            ->all();
        $this->relationshipModes = $relationshipCatalog->validateModes(
            $this->dataSource,
            $this->relationships,
            collect($this->relationshipModes)->only($this->relationships)->all(),
        );
        $allowedFields = array_keys($relationshipCatalog->fields($this->dataSource, $this->relationships));
        $queryFieldKeys = $relationshipCatalog->queryFieldKeys($this->dataSource, $this->relationships);
        $this->selectedFields = array_values(array_intersect($this->selectedFields, $allowedFields));
        if ($this->selectedFields === []) {
            $this->selectedFields = array_values(array_intersect(
                app(ReportDesignerCatalog::class)->defaultFields($this->dataSource),
                $allowedFields,
            ));
            if ($this->selectedFields === []) {
                $this->selectedFields = array_slice($allowedFields, 0, 1);
            }
        }

        $this->calculations = collect($this->calculations)
            ->filter(fn (array $calculation): bool => blank($calculation['field'] ?? null) || in_array($calculation['field'], $queryFieldKeys, true))
            ->values()
            ->all();
        if (filled($this->groupBy) && ! in_array($this->groupBy, $queryFieldKeys, true)) {
            $this->groupBy = '';
            $this->presentationType = ReportDesignerCatalog::PRESENTATION_TABLE;
        }
        if (filled($this->sortField) && ! in_array($this->sortField, $queryFieldKeys, true)) {
            $this->sortField = '';
        }

        foreach ($this->conditionTree['groups'] ?? [] as $groupIndex => $group) {
            $this->conditionTree['groups'][$groupIndex]['conditions'] = collect($group['conditions'] ?? [])
                ->filter(fn (array $condition): bool => in_array($condition['field'] ?? null, $queryFieldKeys, true))
                ->values()
                ->all();
        }

        $this->previewResult = [];
        $this->resetValidation();
    }

    public function updatedRelationshipModes(): void
    {
        abort_if($this->readOnly || ! $this->editorOpen, 403);

        $this->relationshipModes = app(ReportRelationshipCatalog::class)->validateModes(
            $this->dataSource,
            $this->relationships,
            $this->relationshipModes,
        );
        $this->previewResult = [];
        $this->resetValidation('relationshipModes');
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
        $this->relationships = app(ReportRelationshipCatalog::class)->resolve(
            $this->dataSource,
            $definition->relationships,
            app(ReportRelationshipCatalog::class)->usedFields([
                'selected_fields' => $definition->selected_fields,
                'calculations' => $definition->calculations ?? [],
                'group_by' => $definition->group_by,
                'sort_field' => $definition->sort_field,
                'filters' => $filters,
            ]),
        );
        $this->relationshipModes = app(ReportRelationshipCatalog::class)->validateModes(
            $this->dataSource,
            $this->relationships,
            $definition->relationship_modes,
        );
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
        $this->conditionTree = app(ReportConditionService::class)->editorTree($filters['condition_tree'] ?? []);
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

        $previewUser = $this->rolePreviewUser();
        if ($this->getErrorBag()->has('previewUserId')) {
            $this->previewResult = [];

            return;
        }
        $previewUser ??= auth()->user();

        if (! array_key_exists($definition['data_source'], app(ReportDesignerCatalog::class)->sources($previewUser))) {
            $this->previewResult = [];
            $this->addError('preview', __('report_designer.role_preview.source_unavailable'));

            return;
        }

        try {
            $this->previewResult = app(ReportDesignerQueryService::class)->preview([
                'data_source' => $definition['data_source'],
                'relationships' => $definition['relationships'],
                'relationship_modes' => $definition['relationship_modes'],
                'selected_fields' => $definition['selected_fields'],
                'calculations' => $definition['calculations'],
                'group_by' => $definition['group_by'],
                'filters' => $definition['filters'],
                'sort_field' => $definition['sort_field'],
                'sort_direction' => $definition['sort_direction'],
            ], $previewUser);
            $this->resetErrorBag('preview');
        } catch (ReportQueryTimeoutException $exception) {
            $this->previewResult = [];
            $this->addError('preview', $exception->userMessage());
        }
    }

    public function delete(int $definitionId): void
    {
        $this->authorizePermission('report-designer.delete');
        ReportDefinition::query()
            ->tap(fn ($query) => app(ReportDefinitionAccess::class)->scopeManageable($query, auth()->user()))
            ->findOrFail($definitionId)
            ->delete();

        if ($this->editingId === $definitionId) {
            $this->resetEditor();
        }
        if ($this->historyDefinitionId === $definitionId) {
            $this->closeHistory();
        }

        session()->flash('status', __('report_designer.messages.deleted'));
    }

    public function openHistory(int $definitionId): void
    {
        $this->authorizePermission('report-designer.update');
        $definition = ReportDefinition::query()
            ->whereIn('data_source', $this->availableSourceKeys())
            ->tap(fn ($query) => app(ReportDefinitionAccess::class)->scopeManageable($query, auth()->user()))
            ->findOrFail($definitionId);

        $this->historyDefinitionId = $definition->id;
        $this->resetValidation('revision');
    }

    public function closeHistory(): void
    {
        $this->historyDefinitionId = null;
        $this->resetValidation('revision');
    }

    public function restoreRevision(int $revisionId): void
    {
        $this->authorizePermission('report-designer.update');
        $definition = ReportDefinition::query()
            ->whereIn('data_source', $this->availableSourceKeys())
            ->tap(fn ($query) => app(ReportDefinitionAccess::class)->scopeManageable($query, auth()->user()))
            ->findOrFail($this->historyDefinitionId);
        $revision = ReportDefinitionRevision::query()
            ->where('report_definition_id', $definition->id)
            ->findOrFail($revisionId);

        app(ReportVersionService::class)->restore($definition, $revision, auth()->user());

        if ($this->editingId === $definition->id) {
            $this->loadDefinition($definition->id, false);
        }

        session()->flash('status', __('report_designer.messages.revision_restored', [
            'revision' => $revision->revision_number,
        ]));
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
        $audit = app(ReportAuditService::class);
        $before = $audit->dashboardSnapshot($definition);

        DB::transaction(function () use ($definition, $roleIds, $validated, $audit, $before): void {
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
            ])->saveQuietly();

            $audit->dashboardUpdated($definition, $before, $audit->dashboardSnapshot($definition));
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

        $audit = app(ReportAuditService::class);

        DB::transaction(function () use ($validated, $audit): void {
            $currentIds = DB::table('report_dashboard_placements')
                ->where('role_id', $this->layoutRoleId)
                ->lockForUpdate()
                ->pluck('report_definition_id')
                ->map(fn ($id) => (int) $id);
            $definitions = ReportDefinition::query()->whereIn('id', $currentIds)->get()->keyBy('id');
            $before = $definitions->mapWithKeys(
                fn (ReportDefinition $definition): array => [$definition->id => $audit->dashboardSnapshot($definition)],
            );
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

            $definitions->each(function (ReportDefinition $definition) use ($audit, $before): void {
                $definition->forceFill([
                    'status' => $definition->dashboardRoles()->exists()
                        ? ReportDefinition::STATUS_PUBLISHED
                        : ReportDefinition::STATUS_DRAFT,
                    'updated_by' => auth()->id(),
                ])->saveQuietly();

                $audit->dashboardUpdated(
                    $definition,
                    $before->get($definition->id, []),
                    $audit->dashboardSnapshot($definition),
                );
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
            'relationships' => ['array'],
            'relationships.*' => ['string'],
            'relationshipModes' => ['array'],
            'relationshipModes.*' => ['string'],
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
            'conditionTree' => ['array'],
            'conditionTree.operator' => ['required', Rule::in(['and', 'or'])],
            'conditionTree.groups' => ['array', 'max:'.ReportConditionService::GROUP_LIMIT],
            'conditionTree.groups.*.operator' => ['required', Rule::in(['and', 'or'])],
            'conditionTree.groups.*.conditions' => ['array'],
            'conditionTree.groups.*.conditions.*.field' => ['required', 'string'],
            'conditionTree.groups.*.conditions.*.operator' => ['required', 'string'],
            'conditionTree.groups.*.conditions.*.value' => ['nullable'],
            'conditionTree.groups.*.conditions.*.value_to' => ['nullable'],
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
        $conditionTree = app(ReportConditionService::class)->validate($validated['dataSource'], $validated['conditionTree']);
        $relationships = app(ReportRelationshipCatalog::class)->validate(
            $validated['dataSource'],
            $validated['relationships'],
            app(ReportRelationshipCatalog::class)->usedFields([
                'selected_fields' => $fields,
                'calculations' => $calculations,
                'group_by' => $groupBy,
                'sort_field' => $sortField,
                'filters' => ['condition_tree' => $conditionTree],
            ]),
        );
        $relationshipModes = app(ReportRelationshipCatalog::class)->validateModes(
            $validated['dataSource'],
            $relationships,
            $validated['relationshipModes'],
        );

        return [
            'name' => trim($validated['name'] ?? ''),
            'description' => filled($validated['description']) ? trim($validated['description']) : null,
            'data_source' => $validated['dataSource'],
            'relationships' => $relationships,
            'relationship_modes' => $relationshipModes,
            'selected_fields' => $fields,
            'calculations' => $calculations,
            'group_by' => $groupBy,
            'presentation' => $presentation,
            'filters' => [
                'status' => $validated['statusFilter'],
                'search' => trim($validated['searchFilter'] ?? ''),
                'date_from' => $validated['dateFrom'] ?? '',
                'date_to' => $validated['dateTo'] ?? '',
                'condition_tree' => $conditionTree,
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
        $this->relationships = app(ReportRelationshipCatalog::class)->infer($this->dataSource, $this->selectedFields);
        $this->relationshipModes = app(ReportRelationshipCatalog::class)->validateModes($this->dataSource, $this->relationships, null);
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
        $this->conditionTree = app(ReportConditionService::class)->emptyTree();
        $this->sortField = '';
        $this->sortDirection = 'asc';
        $this->previewResult = [];
        $this->previewRoleId = null;
        $this->previewUserId = null;
        $this->resetValidation();
    }

    public function updatedPreviewRoleId(): void
    {
        $this->previewUserId = null;
        $this->previewResult = [];
        $this->resetValidation(['previewRoleId', 'previewUserId', 'preview']);
    }

    public function updatedPreviewUserId(): void
    {
        $this->previewResult = [];
        $this->resetValidation(['previewUserId', 'preview']);
    }

    protected function rolePreviewUser(): ?User
    {
        if (! $this->previewRoleId) {
            $this->previewUserId = null;

            return null;
        }

        abort_unless(auth()->user()?->can('report-dashboard-layout.manage'), 403);

        $role = $this->availableDashboardRoles()->firstWhere('id', $this->previewRoleId);
        if (! $role || ! $this->previewUserId) {
            $this->addError('previewUserId', __('report_designer.role_preview.user_required'));

            return null;
        }

        $user = User::query()
            ->whereKey($this->previewUserId)
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereKey($role->id))
            ->first();

        if (! $user) {
            $this->addError('previewUserId', __('report_designer.role_preview.user_invalid'));

            return null;
        }

        $previewUser = clone $user;
        $previewUser->setRelation('roles', collect([$role]));
        $previewUser->setRelation('permissions', collect());

        return $previewUser;
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

    public function addConditionGroup(): void
    {
        abort_if($this->readOnly || ! $this->editorOpen, 403);

        if (count($this->conditionTree['groups'] ?? []) >= ReportConditionService::GROUP_LIMIT) {
            return;
        }

        $this->conditionTree['groups'][] = ['operator' => 'and', 'conditions' => []];
        $this->previewResult = [];
    }

    public function removeConditionGroup(int $groupIndex): void
    {
        abort_if($this->readOnly || ! $this->editorOpen, 403);

        unset($this->conditionTree['groups'][$groupIndex]);
        $this->conditionTree['groups'] = array_values($this->conditionTree['groups']);
        if ($this->conditionTree['groups'] === []) {
            $this->conditionTree = app(ReportConditionService::class)->emptyTree();
        }
        $this->previewResult = [];
        $this->resetValidation('conditionTree');
    }

    public function addCondition(int $groupIndex): void
    {
        abort_if($this->readOnly || ! $this->editorOpen, 403);

        $currentCount = collect($this->conditionTree['groups'] ?? [])->sum(fn (array $group): int => count($group['conditions'] ?? []));
        $fields = app(ReportConditionService::class)->fields($this->dataSource);
        if ($currentCount >= ReportConditionService::CONDITION_LIMIT || $fields === [] || ! isset($this->conditionTree['groups'][$groupIndex])) {
            return;
        }

        $field = (string) array_key_first($fields);
        $operator = (string) array_key_first($fields[$field]['operators']);
        $value = $fields[$field]['options'] === [] ? '' : (string) array_key_first($fields[$field]['options']);
        $this->conditionTree['groups'][$groupIndex]['conditions'][] = compact('field', 'operator', 'value') + ['value_to' => ''];
        $this->previewResult = [];
    }

    public function removeCondition(int $groupIndex, int $conditionIndex): void
    {
        abort_if($this->readOnly || ! $this->editorOpen, 403);

        unset($this->conditionTree['groups'][$groupIndex]['conditions'][$conditionIndex]);
        $this->conditionTree['groups'][$groupIndex]['conditions'] = array_values($this->conditionTree['groups'][$groupIndex]['conditions']);
        $this->previewResult = [];
        $this->resetValidation('conditionTree');
    }

    public function updatedConditionTree(mixed $value, string $key): void
    {
        $parts = explode('.', $key);
        if (count($parts) === 5 && $parts[0] === 'groups' && $parts[2] === 'conditions') {
            $groupIndex = (int) $parts[1];
            $conditionIndex = (int) $parts[3];
            $condition = &$this->conditionTree['groups'][$groupIndex]['conditions'][$conditionIndex];
            $fields = app(ReportConditionService::class)->fields($this->dataSource);

            if (isset($condition, $fields[$condition['field'] ?? ''])) {
                $field = $fields[$condition['field']];
                if (! array_key_exists((string) ($condition['operator'] ?? ''), $field['operators'])) {
                    $condition['operator'] = (string) array_key_first($field['operators']);
                }
                if ($field['options'] !== [] && ! array_key_exists((string) ($condition['value'] ?? ''), $field['options'])) {
                    $condition['value'] = (string) array_key_first($field['options']);
                }
                $condition['value_to'] ??= '';
            }
            unset($condition);
        }

        $this->previewResult = [];
        $this->resetValidation('conditionTree');
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
                    @php($compatibility = $definitionCompatibility[$definition->id])
                    @php($savedGuidance = $definitionGuidance[$definition->id])
                    <article class="rounded-2xl border border-white/10 bg-white/[0.03] p-4" wire:key="report-definition-{{ $definition->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate font-semibold text-white">{{ $definition->name }}</div>
                                <div class="mt-1 text-xs text-neutral-400">{{ $allSources[$definition->data_source]['label'] ?? $definition->data_source }} · {{ __('report_designer.statuses.'.$definition->status) }}</div>
                                @if($savedGuidance['sentence'] !== '')
                                    <p class="mt-3 max-w-3xl text-sm leading-6 text-neutral-300" data-report-query-summary>{{ $savedGuidance['sentence'] }}</p>
                                    <div class="mt-3 flex flex-wrap gap-2" data-report-query-badges>
                                        @foreach($savedGuidance['badges'] as $badge)
                                            <span class="rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[0.7rem] text-neutral-300">{{ $badge }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                @if(! $compatibility['compatible'])
                                    <div class="mt-3 rounded-xl border border-amber-400/30 bg-amber-400/10 px-3 py-2 text-xs leading-5 text-amber-100" data-report-incompatible>{{ $compatibility['reason'] }}</div>
                                @endif
                                @if($definition->library_item_uuid)
                                    <div class="mt-2 inline-flex rounded-full bg-emerald-400/10 px-2.5 py-1 text-xs text-emerald-200">{{ __('report_library.labels.installed_revision', ['version' => $definition->library_revision]) }}</div>
                                @endif
                                <div class="mt-2 text-xs text-neutral-500">{{ __('report_designer.saved.updated', ['date' => $definition->updated_at->diffForHumans()]) }}</div>
                            </div>
                            <div class="flex shrink-0 gap-2">
                                @if($compatibility['compatible'])
                                    @can('report-designer.update')
                                        <button type="button" wire:click="edit({{ $definition->id }})" class="admin-icon-button" title="{{ __('report_designer.actions.edit') }}"><x-admin-action-icon name="edit" /></button>
                                        <button type="button" wire:click="openHistory({{ $definition->id }})" class="admin-icon-button" title="{{ __('report_designer.actions.version_history') }}"><x-admin-action-icon name="history" /></button>
                                    @else
                                        <button type="button" wire:click="viewDefinition({{ $definition->id }})" class="admin-icon-button" title="{{ __('report_designer.actions.open') }}"><x-admin-action-icon name="open" /></button>
                                    @endcan
                                @endif
                                @can('report-designer.delete')
                                    <button type="button" wire:click="delete({{ $definition->id }})" wire:confirm="{{ __('report_designer.actions.delete_confirm') }}" class="admin-icon-button" title="{{ __('report_designer.actions.delete') }}"><x-admin-action-icon name="delete" /></button>
                                @endcan
                                @if($compatibility['compatible'] && auth()->user()?->can('report-dashboard-layout.manage'))
                                    <button type="button" wire:click="managePlacement({{ $definition->id }})" class="admin-icon-button" title="{{ __('report_designer.actions.manage_placement') }}"><x-admin-action-icon name="chart" /></button>
                                @endif
                            </div>
                        </div>
                        @if($compatibility['compatible'])
                            <div class="mt-4 flex flex-wrap gap-2 border-t border-white/5 pt-3">
                                <a href="{{ route('reports.designer.export.xlsx', $definition) }}" class="pill-link text-xs" data-report-export-xlsx>{{ __('report_designer.actions.export_xlsx') }}</a>
                                <a href="{{ route('reports.designer.export.pdf', $definition) }}" target="_blank" rel="noopener" class="pill-link text-xs" data-report-export-pdf>{{ __('report_designer.actions.export_pdf') }}</a>
                            </div>
                        @endif
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

                    @if($availableRelationships !== [])
                        <section class="rounded-2xl border border-sky-300/20 bg-sky-400/[0.05] p-4" data-report-relationships>
                            <div class="text-sm font-semibold text-white">{{ __('report_designer.relationships.title') }}</div>
                            <p class="mt-1 max-w-3xl text-xs leading-5 text-neutral-400">{{ __('report_designer.relationships.help') }}</p>
                            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                @foreach($availableRelationships as $relationshipKey => $relationship)
                                    <div @class([
                                        'rounded-xl border p-4 transition',
                                        'border-sky-300/35 bg-sky-300/10' => in_array($relationshipKey, $relationships, true),
                                        'border-white/10 bg-black/10 hover:border-white/20' => ! in_array($relationshipKey, $relationships, true),
                                    ])>
                                        <label class="flex cursor-pointer items-start gap-3">
                                            <input wire:model.live="relationships" type="checkbox" value="{{ $relationshipKey }}" class="mt-1 rounded border-white/20 bg-transparent" @disabled($readOnly)>
                                            <span class="min-w-0">
                                                <span class="font-semibold text-white">{{ $relationship['label'] }}</span>
                                                <span class="mt-1 block text-xs leading-5 text-neutral-400">{{ $relationship['description'] }}</span>
                                                <span class="mt-3 flex flex-wrap gap-2">
                                                    <span class="rounded-full bg-white/8 px-2 py-1 text-[0.65rem] text-neutral-300">{{ $relationship['cardinality'] }}</span>
                                                    <span class="rounded-full bg-white/8 px-2 py-1 text-[0.65rem] text-neutral-300">{{ __('report_designer.relationships.fields_available', ['count' => count($relationship['fields'])]) }}</span>
                                                </span>
                                            </span>
                                        </label>
                                        @if($relationship['cardinality_key'] === 'many' && in_array($relationshipKey, $relationships, true))
                                            <div class="mt-4 grid gap-2 border-t border-white/10 pt-4" data-report-relationship-mode="{{ $relationshipKey }}">
                                                <div class="text-xs font-semibold text-neutral-300">{{ __('report_designer.relationships.modes.title') }}</div>
                                                @foreach([\App\Services\ReportRelationshipCatalog::MODE_SUMMARY, \App\Services\ReportRelationshipCatalog::MODE_DETAILED] as $mode)
                                                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-white/10 bg-black/10 p-3">
                                                        <input wire:model.live="relationshipModes.{{ $relationshipKey }}" type="radio" value="{{ $mode }}" class="mt-1 border-white/20 bg-transparent" @disabled($readOnly)>
                                                        <span>
                                                            <span class="block text-xs font-semibold text-white">{{ __('report_designer.relationships.modes.'.$mode.'.label') }}</span>
                                                            <span class="mt-1 block text-xs leading-5 text-neutral-400">{{ __('report_designer.relationships.modes.'.$mode.'.help') }}</span>
                                                        </span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            @error('relationships') <span class="mt-3 block text-xs text-red-300">{{ $message }}</span> @enderror
                            @error('relationshipModes') <span class="mt-3 block text-xs text-red-300">{{ $message }}</span> @enderror
                        </section>
                    @endif

                    <div>
                        <div class="text-sm font-semibold text-white">{{ __('report_designer.form.fields') }}</div>
                        <p class="mt-1 text-xs leading-5 text-neutral-400">{{ __('report_designer.form.fields_help') }}</p>
                        <div class="mt-3 grid gap-4">
                            @foreach($fieldGroups as $fieldGroup)
                                <section class="rounded-xl border border-white/8 bg-white/[0.02] p-3" data-report-field-group="{{ $fieldGroup['key'] }}">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-neutral-400">{{ $fieldGroup['label'] }}</div>
                                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        @foreach ($fieldGroup['fields'] as $fieldKey => $field)
                                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-white/[0.03] px-3 py-3 text-sm text-neutral-200">
                                                <input wire:model.live="selectedFields" type="checkbox" value="{{ $fieldKey }}" class="mt-1 rounded border-white/20 bg-transparent" @disabled($readOnly)>
                                                <span class="min-w-0">
                                                    <span class="flex flex-wrap items-center gap-2">
                                                        <span class="font-medium text-white">{{ $field['label'] }}</span>
                                                        <span class="rounded-full bg-white/8 px-2 py-0.5 text-[0.65rem] text-neutral-300">{{ $field['type_label'] }}</span>
                                                    </span>
                                                    <span class="mt-1 block text-xs leading-5 text-neutral-400" data-report-field-description>{{ $field['description'] }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </section>
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
                                                <select wire:model.live="calculations.{{ $calculationIndex }}.field" class="rounded-xl px-4 py-3" @disabled($readOnly)>
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
                            <select wire:model.live="statusFilter" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                                @foreach ($statusFilters as $filterKey => $filterLabel)
                                    <option value="{{ $filterKey }}">{{ $filterLabel }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.search') }}</span>
                            <input wire:model.live.debounce.400ms="searchFilter" type="search" class="rounded-xl px-4 py-3" placeholder="{{ __('report_designer.form.search_placeholder') }}" @disabled($readOnly)>
                        </label>
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.date_from') }}</span>
                            <input wire:model.live="dateFrom" type="date" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                        </label>
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.date_to') }}</span>
                            <input wire:model.live="dateTo" type="date" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                        </label>
                    </div>

                    <section class="rounded-2xl border border-violet-300/20 bg-violet-400/[0.05] p-4" data-report-condition-builder>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-white">{{ __('report_designer.conditions.title') }}</div>
                                <p class="mt-1 max-w-3xl text-xs leading-5 text-neutral-400">{{ __('report_designer.conditions.help') }}</p>
                            </div>
                            @if(! $readOnly && count($conditionTree['groups'] ?? []) < \App\Services\ReportConditionService::GROUP_LIMIT)
                                <button type="button" wire:click="addConditionGroup" class="pill-link">{{ __('report_designer.conditions.add_group') }}</button>
                            @endif
                        </div>

                        @if(count($conditionTree['groups'] ?? []) > 1)
                            <label class="mt-4 flex flex-wrap items-center gap-3 text-sm text-neutral-200">
                                <span>{{ __('report_designer.conditions.match_groups') }}</span>
                                <select wire:model.live="conditionTree.operator" class="rounded-xl px-3 py-2" @disabled($readOnly)>
                                    <option value="and">{{ __('report_designer.conditions.logic.and') }}</option>
                                    <option value="or">{{ __('report_designer.conditions.logic.or') }}</option>
                                </select>
                            </label>
                        @endif

                        <div class="mt-4 grid gap-4">
                            @foreach($conditionTree['groups'] ?? [] as $groupIndex => $conditionGroup)
                                <article class="rounded-xl border border-white/10 bg-black/10 p-4" wire:key="report-condition-group-{{ $groupIndex }}">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <label class="flex items-center gap-3 text-sm text-neutral-200">
                                            <span>{{ __('report_designer.conditions.match_conditions') }}</span>
                                            <select wire:model.live="conditionTree.groups.{{ $groupIndex }}.operator" class="rounded-xl px-3 py-2" @disabled($readOnly)>
                                                <option value="and">{{ __('report_designer.conditions.logic.and') }}</option>
                                                <option value="or">{{ __('report_designer.conditions.logic.or') }}</option>
                                            </select>
                                        </label>
                                        <div class="flex gap-2">
                                            @if(! $readOnly && $conditionFields !== [])
                                                <button type="button" wire:click="addCondition({{ $groupIndex }})" class="pill-link text-xs">{{ __('report_designer.conditions.add_condition') }}</button>
                                            @endif
                                            @if(! $readOnly && count($conditionTree['groups'] ?? []) > 1)
                                                <button type="button" wire:click="removeConditionGroup({{ $groupIndex }})" class="admin-icon-button admin-icon-button--danger" title="{{ __('report_designer.conditions.remove_group') }}"><x-admin-action-icon name="delete" /></button>
                                            @endif
                                        </div>
                                    </div>

                                    @if(($conditionGroup['conditions'] ?? []) === [])
                                        <div class="mt-3 rounded-lg border border-dashed border-white/10 px-3 py-4 text-center text-xs text-neutral-500">{{ __('report_designer.conditions.empty_group') }}</div>
                                    @else
                                        <div class="mt-3 grid gap-3">
                                            @foreach($conditionGroup['conditions'] as $conditionIndex => $condition)
                                                @php($conditionField = $conditionFields[$condition['field'] ?? ''] ?? null)
                                                <div class="grid gap-3 rounded-lg border border-white/8 bg-white/[0.025] p-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.85fr)_minmax(0,1fr)_auto] lg:items-end" wire:key="report-condition-{{ $groupIndex }}-{{ $conditionIndex }}">
                                                    <label class="grid gap-2 text-xs text-neutral-300">
                                                        <span>{{ __('report_designer.conditions.field') }}</span>
                                                        <select wire:model.live="conditionTree.groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.field" class="rounded-xl px-3 py-2" @disabled($readOnly)>
                                                            @foreach($conditionFields as $fieldKey => $field)
                                                                <option value="{{ $fieldKey }}">{{ $field['label'] }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label class="grid gap-2 text-xs text-neutral-300">
                                                        <span>{{ __('report_designer.conditions.operator') }}</span>
                                                        <select wire:model.live="conditionTree.groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.operator" class="rounded-xl px-3 py-2" @disabled($readOnly)>
                                                            @foreach(($conditionField['operators'] ?? []) as $operatorKey => $operatorLabel)
                                                                <option value="{{ $operatorKey }}">{{ $operatorLabel }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <div class="grid gap-2 text-xs text-neutral-300">
                                                        <span>{{ __('report_designer.conditions.value') }}</span>
                                                        @if(in_array($condition['operator'] ?? '', ['is_empty', 'is_not_empty'], true))
                                                            <div class="rounded-xl border border-white/10 bg-white/[0.02] px-3 py-2 text-neutral-500">{{ __('report_designer.conditions.no_value') }}</div>
                                                        @elseif(($conditionField['options'] ?? []) !== [])
                                                            <select wire:model.live="conditionTree.groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.value" class="rounded-xl px-3 py-2" @disabled($readOnly)>
                                                                @foreach($conditionField['options'] as $optionKey => $optionLabel)
                                                                    <option value="{{ $optionKey }}">{{ $optionLabel }}</option>
                                                                @endforeach
                                                            </select>
                                                        @else
                                                            <div class="grid gap-2 sm:grid-cols-2">
                                                                <input wire:model.live.debounce.400ms="conditionTree.groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.value" type="{{ ($conditionField['type'] ?? '') === 'date' ? 'date' : (($conditionField['type'] ?? '') === 'number' ? 'number' : 'text') }}" step="any" class="rounded-xl px-3 py-2" @disabled($readOnly)>
                                                                @if(($condition['operator'] ?? '') === 'between')
                                                                    <input wire:model.live.debounce.400ms="conditionTree.groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.value_to" type="{{ ($conditionField['type'] ?? '') === 'date' ? 'date' : 'number' }}" step="any" class="rounded-xl px-3 py-2" aria-label="{{ __('report_designer.conditions.second_value') }}" @disabled($readOnly)>
                                                                @endif
                                                            </div>
                                                        @endif
                                                    </div>
                                                    @if(! $readOnly)
                                                        <button type="button" wire:click="removeCondition({{ $groupIndex }}, {{ $conditionIndex }})" class="admin-icon-button admin-icon-button--danger" title="{{ __('report_designer.conditions.remove_condition') }}"><x-admin-action-icon name="delete" /></button>
                                                    @endif
                                                    @error("conditionTree.groups.$groupIndex.conditions.$conditionIndex") <span class="text-xs text-red-300 lg:col-span-4">{{ $message }}</span> @enderror
                                                    @error("conditionTree.groups.$groupIndex.conditions.$conditionIndex.value") <span class="text-xs text-red-300 lg:col-span-4">{{ $message }}</span> @enderror
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                        @error('conditionTree') <span class="mt-3 block text-xs text-red-300">{{ $message }}</span> @enderror
                    </section>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.sort') }}</span>
                            <select wire:model.live="sortField" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                                <option value="">{{ __('report_designer.form.default_sort') }}</option>
                                @foreach ($sortableFields as $fieldKey => $field)
                                    <option value="{{ $fieldKey }}">{{ $field['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grid gap-2 text-sm text-neutral-200">
                            <span>{{ __('report_designer.form.direction') }}</span>
                            <select wire:model.live="sortDirection" class="rounded-xl px-4 py-3" @disabled($readOnly)>
                                <option value="asc">{{ __('report_designer.form.ascending') }}</option>
                                <option value="desc">{{ __('report_designer.form.descending') }}</option>
                            </select>
                        </label>
                    </div>

                    <section class="rounded-2xl border border-emerald-300/20 bg-emerald-400/[0.06] p-4" data-report-guidance>
                        <div class="eyebrow">{{ __('report_designer.guidance.eyebrow') }}</div>
                        <h3 class="mt-2 text-lg font-semibold text-white">{{ __('report_designer.guidance.title') }}</h3>
                        <p class="mt-2 text-sm leading-7 text-neutral-100" data-report-query-sentence>{{ $designGuidance['sentence'] }}</p>

                        <ol class="mt-5 grid gap-3 lg:grid-cols-4" data-report-query-flow>
                            @foreach($designGuidance['flow'] as $flowIndex => $step)
                                <li class="relative rounded-xl border border-emerald-200/15 bg-black/10 px-4 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="grid size-6 place-items-center rounded-full bg-emerald-300/15 text-xs font-bold text-emerald-100">{{ $flowIndex + 1 }}</span>
                                        <span class="text-xs font-semibold uppercase tracking-wide text-emerald-200">{{ $step['label'] }}</span>
                                    </div>
                                    <p class="mt-3 text-sm leading-6 text-white">{{ $step['value'] }}</p>
                                    @if(! $loop->last)
                                        <span class="absolute -end-2 top-1/2 hidden -translate-y-1/2 text-emerald-300/50 lg:block" aria-hidden="true">→</span>
                                    @endif
                                </li>
                            @endforeach
                        </ol>

                        <dl class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-report-query-outline>
                            @foreach($designGuidance['facts'] as $fact)
                                <div class="rounded-xl border border-white/8 bg-black/10 px-3 py-3">
                                    <dt class="text-xs text-neutral-400">{{ $fact['label'] }}</dt>
                                    <dd class="mt-1 text-sm leading-6 text-white">{{ $fact['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>

                        @if($designGuidance['warnings'] !== [])
                            <div class="mt-4 rounded-xl border border-amber-400/30 bg-amber-400/10 px-4 py-3" data-report-guidance-warnings>
                                <div class="text-sm font-semibold text-amber-100">{{ __('report_designer.guidance.check_title') }}</div>
                                <ul class="mt-2 list-disc space-y-1 ps-5 text-xs leading-5 text-amber-100">
                                    @foreach($designGuidance['warnings'] as $warning)
                                        <li>{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @else
                            <div class="mt-4 text-xs leading-5 text-emerald-200" data-report-guidance-ready>{{ __('report_designer.guidance.ready') }}</div>
                        @endif
                    </section>

                    @can('report-dashboard-layout.manage')
                        <section class="rounded-2xl border border-sky-300/20 bg-sky-400/[0.06] p-4" data-report-role-preview>
                            <div class="eyebrow">{{ __('report_designer.role_preview.eyebrow') }}</div>
                            <h3 class="mt-2 text-lg font-semibold text-white">{{ __('report_designer.role_preview.title') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-neutral-300">{{ __('report_designer.role_preview.help') }}</p>

                            <div class="mt-4 grid gap-4 md:grid-cols-2">
                                <label class="grid gap-2 text-sm text-neutral-200">
                                    <span>{{ __('report_designer.role_preview.role') }}</span>
                                    <select wire:model.live="previewRoleId" class="rounded-xl px-4 py-3">
                                        <option value="">{{ __('report_designer.role_preview.my_access') }}</option>
                                        @foreach($dashboardRoles as $role)
                                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="grid gap-2 text-sm text-neutral-200">
                                    <span>{{ __('report_designer.role_preview.representative_user') }}</span>
                                    <select wire:model.live="previewUserId" class="rounded-xl px-4 py-3" @disabled(! $previewRoleId)>
                                        <option value="">{{ $previewRoleId ? __('report_designer.role_preview.choose_user') : __('report_designer.role_preview.choose_role_first') }}</option>
                                        @foreach($previewUsers as $previewUser)
                                            <option value="{{ $previewUser->id }}">{{ $previewUser->name ?: $previewUser->username }}@if($previewUser->name && $previewUser->username) ({{ $previewUser->username }})@endif</option>
                                        @endforeach
                                    </select>
                                    @error('previewUserId') <span class="text-xs text-red-300">{{ $message }}</span> @enderror
                                </label>
                            </div>

                            @if($previewRole)
                                <div class="mt-4 rounded-xl border border-white/10 bg-black/10 px-4 py-3 text-xs leading-5 text-neutral-300">
                                    <p>{{ __('report_designer.role_preview.scope_help', ['role' => $previewRole->name]) }}</p>
                                    @if($editingId)
                                        <p @class(['mt-2 font-semibold', 'text-emerald-200' => $previewRoleHasPlacement, 'text-amber-200' => ! $previewRoleHasPlacement])>
                                            {{ $previewRoleHasPlacement
                                                ? __('report_designer.role_preview.visible_on_dashboard')
                                                : __('report_designer.role_preview.not_visible_on_dashboard') }}
                                        </p>
                                    @else
                                        <p class="mt-2 font-semibold text-amber-200">{{ __('report_designer.role_preview.unsaved_visibility') }}</p>
                                    @endif
                                </div>
                            @endif
                        </section>
                    @endcan

                    @error('preview')
                        <div class="rounded-xl border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm leading-6 text-amber-100" data-report-timeout-error>{{ $message }}</div>
                    @enderror

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
                            @if($previewRole && $previewUserId)
                                @php($testedUser = $previewUsers->firstWhere('id', $previewUserId))
                                @if($testedUser)
                                    <div class="mt-2 inline-flex rounded-full border border-sky-300/20 bg-sky-400/10 px-3 py-1 text-xs font-semibold text-sky-100" data-report-role-preview-result>
                                        {{ __('report_designer.role_preview.result_context', [
                                            'role' => $previewRole->name,
                                            'user' => $testedUser->name ?: $testedUser->username,
                                        ]) }}
                                    </div>
                                @endif
                            @endif
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

    <x-admin.modal :show="$historyDefinitionId !== null" :title="__('report_designer.history.title')" close-method="closeHistory" max-width="3xl">
        @if($historyDefinition)
            <div class="grid gap-5" data-report-version-history>
                <div>
                    <div class="font-semibold text-white">{{ $historyDefinition->name }}</div>
                    <p class="mt-1 text-sm leading-6 text-neutral-300">{{ __('report_designer.history.help') }}</p>
                </div>

                @error('revision')
                    <div class="rounded-xl border border-red-300/20 bg-red-400/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div>
                @enderror

                <div class="grid max-h-[60vh] gap-3 overflow-y-auto pe-1">
                    @forelse($historyRevisions as $revision)
                        <article class="rounded-2xl border border-white/10 bg-white/[0.03] p-4" wire:key="report-revision-{{ $revision->id }}">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <div class="font-semibold text-white">{{ __('report_designer.history.revision', ['number' => $revision->revision_number]) }}</div>
                                    <div class="mt-1 text-xs text-neutral-400">
                                        {{ __('report_designer.history.actions.'.$revision->action) }}
                                        · {{ $revision->creator?->name ?: $revision->creator?->username ?: __('report_designer.history.system') }}
                                        · {{ $revision->created_at->format('Y-m-d H:i') }}
                                    </div>
                                    @if($revision->restored_from_revision_number)
                                        <div class="mt-2 text-xs text-amber-200">{{ __('report_designer.history.restored_from', ['number' => $revision->restored_from_revision_number]) }}</div>
                                    @endif
                                </div>
                                @if($revision->revision_number !== $historyRevisions->max('revision_number'))
                                    <button
                                        type="button"
                                        wire:click="restoreRevision({{ $revision->id }})"
                                        wire:confirm="{{ __('report_designer.history.restore_confirm', ['number' => $revision->revision_number]) }}"
                                        class="pill-link text-xs"
                                    >{{ __('report_designer.actions.restore_revision') }}</button>
                                @else
                                    <span class="rounded-full bg-emerald-400/10 px-3 py-1 text-xs text-emerald-200">{{ __('report_designer.history.current') }}</span>
                                @endif
                            </div>

                            <div class="mt-3 grid gap-2 text-xs text-neutral-300 sm:grid-cols-2">
                                <div><span class="text-neutral-500">{{ __('report_designer.form.name') }}:</span> {{ data_get($revision->snapshot, 'name') }}</div>
                                <div><span class="text-neutral-500">{{ __('report_designer.form.source') }}:</span> {{ data_get($sources, data_get($revision->snapshot, 'data_source').'.label', data_get($revision->snapshot, 'data_source')) }}</div>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-white/10 px-4 py-10 text-center text-sm text-neutral-400">{{ __('report_designer.history.empty') }}</div>
                    @endforelse
                </div>

                <div class="flex justify-end">
                    <button type="button" wire:click="closeHistory" class="pill-link">{{ __('report_designer.actions.cancel') }}</button>
                </div>
            </div>
        @endif
    </x-admin.modal>

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
