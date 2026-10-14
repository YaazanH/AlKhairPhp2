<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformReportLibraryItem;
use App\Models\Landlord\PlatformReportLibraryRevision;
use App\Services\ReportDesignerCatalog;
use App\Services\ReportRelationshipCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportLibraryController extends Controller
{
    public function __construct(
        private readonly ReportDesignerCatalog $catalog,
        private readonly ReportRelationshipCatalog $relationships,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        return view('platform.report-library.index', [
            'items' => PlatformReportLibraryItem::query()
                ->with('publishedRevision')
                ->when($search !== '', fn ($query) => $query->where(fn ($matches) => $matches
                    ->where('name->en', 'like', '%'.$search.'%')->orWhere('name->ar', 'like', '%'.$search.'%')->orWhere('system_key', 'like', '%'.$search.'%')))
                ->when(in_array($request->query('kind'), ['report', 'widget', 'both'], true), fn ($query) => $query->where('kind', $request->query('kind')))
                ->when($request->query('state') === 'draft', fn ($query) => $query->whereNull('published_revision_id'))
                ->when($request->query('state') === 'published', fn ($query) => $query->whereNotNull('published_revision_id'))
                ->when($request->query('origin') === 'system', fn ($query) => $query->where('is_system', true))
                ->when($request->query('origin') === 'custom', fn ($query) => $query->where('is_system', false))
                ->latest('updated_at')
                ->orderByDesc('id')->paginate(12)->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        $source = $this->selectedSource($request);

        return view('platform.report-library.form', $this->formPayload(
            new PlatformReportLibraryItem,
            $source,
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $administrator = $request->user('platform');

        $item = DB::connection('landlord')->transaction(function () use ($data, $administrator, $request): PlatformReportLibraryItem {
            $item = PlatformReportLibraryItem::query()->create([
                'uuid' => (string) Str::uuid(),
                ...$data,
                'created_by' => $administrator->id,
                'updated_by' => $administrator->id,
            ]);

            $this->audit($request, 'report_library_item_created', $item);

            return $item;
        });

        return redirect()->route('platform.report-library.edit', $item)
            ->with('status', 'Library draft created. Review it before publishing.');
    }

    public function edit(Request $request, PlatformReportLibraryItem $libraryItem): View
    {
        $canManage = $request->user('platform')->hasPlatformPermission('manage.report-library') && ! $libraryItem->is_system;
        $source = $request->filled('source') && $canManage
            ? $this->selectedSource($request)
            : (string) data_get($libraryItem->draft_definition, 'data_source');

        return view('platform.report-library.form', $this->formPayload($libraryItem, $source));
    }

    public function revision(PlatformReportLibraryItem $libraryItem, PlatformReportLibraryRevision $revision): View
    {
        abort_unless($revision->library_item_id === $libraryItem->id, 404);

        return view('platform.report-library.revision', ['item' => $libraryItem, 'revision' => $revision]);
    }

    public function update(Request $request, PlatformReportLibraryItem $libraryItem): RedirectResponse
    {
        abort_if($libraryItem->is_system, 403, 'System library templates are managed by the application.');
        $data = $this->validated($request);
        $administrator = $request->user('platform');

        DB::connection('landlord')->transaction(function () use ($data, $administrator, $request, $libraryItem): void {
            $libraryItem->update([...$data, 'updated_by' => $administrator->id]);
            $this->audit($request, 'report_library_draft_updated', $libraryItem);
        });

        return back()->with('status', 'Library draft saved. Published tenants still use the previous revision.');
    }

    public function publish(Request $request, PlatformReportLibraryItem $libraryItem): RedirectResponse
    {
        abort_if($libraryItem->is_system, 403, 'System library templates are managed by the application.');
        DB::connection('landlord')->transaction(function () use ($request, $libraryItem): void {
            $item = PlatformReportLibraryItem::query()->lockForUpdate()->findOrFail($libraryItem->id);
            $version = $item->latest_version + 1;
            $revision = $item->revisions()->create([
                'version' => $version,
                'kind' => $item->kind,
                'name' => $item->name,
                'description' => $item->description,
                'definition' => $item->draft_definition,
                'required_modules' => $item->required_modules,
                'published_by' => $request->user('platform')->id,
                'published_at' => now(),
            ]);
            $item->update([
                'latest_version' => $version,
                'published_revision_id' => $revision->id,
                'updated_by' => $request->user('platform')->id,
            ]);
            $this->audit($request, 'report_library_item_published', $item, ['version' => $version]);
        });

        return back()->with('status', 'Version '.$libraryItem->fresh()->latest_version.' published to the library.');
    }

    private function validated(Request $request): array
    {
        $sources = $this->catalog->librarySources();
        $data = $request->validate([
            'kind' => ['required', Rule::in(['report', 'widget', 'both'])],
            'name.en' => ['required', 'string', 'max:160'],
            'name.ar' => ['required', 'string', 'max:160'],
            'description.en' => ['nullable', 'string', 'max:1000'],
            'description.ar' => ['nullable', 'string', 'max:1000'],
            'data_source' => ['required', Rule::in(array_keys($sources))],
            'selected_fields' => ['required', 'array', 'min:1'],
            'selected_fields.*' => ['string'],
            'relationship_modes' => ['nullable', 'array'],
            'relationship_modes.*' => ['string', Rule::in([
                ReportRelationshipCatalog::MODE_SUMMARY,
                ReportRelationshipCatalog::MODE_DETAILED,
            ])],
            'calculations' => ['nullable', 'array', 'max:5'],
            'calculations.*' => ['string'],
            'group_by' => ['nullable', 'string'],
            'presentation_type' => ['required', Rule::in(array_keys($this->catalog->presentationTypes()))],
            'table_density' => ['required', Rule::in(array_keys($this->catalog->tableDensities()))],
            'sort_field' => ['nullable', 'string'],
            'sort_direction' => ['required', Rule::in(['asc', 'desc'])],
        ]);

        $source = $data['data_source'];
        $fields = $this->catalog->validateFields($source, $data['selected_fields']);
        $groupBy = $this->catalog->validateGrouping($source, $data['group_by'] ?? null);
        [$sortField, $sortDirection] = $this->catalog->validateSort($source, $data['sort_field'] ?? null, $data['sort_direction']);
        $calculationValues = $data['calculations'] ?? ($request->input('workspace_form') === 'report_definition' ? [] : ['count']);
        $calculations = $this->catalog->validateCalculations($source, $this->parseCalculations($calculationValues));
        $presentation = $this->catalog->validatePresentation([
            'type' => $data['presentation_type'],
            'density' => $data['table_density'],
        ], $groupBy, false, $source, $calculations);
        $definitionFields = [
            ...$fields,
            ...array_column($calculations, 'field'),
            $groupBy,
            $sortField,
        ];
        $relationships = $this->relationships->infer($source, array_values(array_filter($definitionFields)));
        $relationshipModes = $this->relationships->validateModes(
            $source,
            $relationships,
            collect($data['relationship_modes'] ?? [])->only($relationships)->all(),
        );

        return [
            'kind' => $data['kind'],
            'name' => $data['name'],
            'description' => array_filter($data['description'] ?? [], fn (?string $value): bool => filled($value)),
            'draft_definition' => [
                'data_source' => $source,
                'relationships' => $relationships,
                'relationship_modes' => $relationshipModes,
                'selected_fields' => $fields,
                'calculations' => $calculations,
                'group_by' => $groupBy,
                'presentation' => $presentation,
                'filters' => [],
                'sort_field' => $sortField,
                'sort_direction' => $sortDirection,
            ],
            'required_modules' => $this->catalog->requiredModulesForDefinition($source, [
                ...$definitionFields,
            ]),
        ];
    }

    private function parseCalculations(array $values): array
    {
        return collect($values)->map(function (string $value): array {
            [$operation, $field] = array_pad(explode(':', $value, 2), 2, null);

            return ['operation' => $operation, 'field' => $field];
        })->values()->all();
    }

    private function selectedSource(Request $request): string
    {
        $sources = $this->catalog->librarySources();
        $source = (string) $request->query('source', array_key_first($sources));

        abort_unless(array_key_exists($source, $sources), 404);

        return $source;
    }

    private function formPayload(PlatformReportLibraryItem $item, string $source): array
    {
        return [
            'item' => $item,
            'source' => $source,
            'sources' => $this->catalog->librarySources(),
            'fields' => $this->catalog->fields($source),
            'availableRelationships' => $this->relationships->available($source),
            'groupableFields' => $this->catalog->groupableFields($source),
            'sortableFields' => $this->catalog->sortableFields($source),
            'calculableFields' => $this->catalog->calculableFields($source),
            'calculationOperations' => $this->catalog->calculationOperations(),
            'presentationTypes' => $this->catalog->presentationTypes(),
            'tableDensities' => $this->catalog->tableDensities(),
            'workspaceView' => $item->exists && in_array(request('view'), ['publishing', 'history'], true) ? request('view') : 'definition',
            'revisions' => $item->exists ? $item->revisions()->latest('version')->paginate(12)->withQueryString() : null,
        ];
    }

    private function audit(Request $request, string $event, PlatformReportLibraryItem $item, array $properties = []): void
    {
        PlatformAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'platform_administrator_id' => $request->user('platform')->id,
            'event' => $event,
            'properties' => ['library_item_id' => $item->id, 'uuid' => $item->uuid] + $properties,
            'ip_address' => $request->ip(),
        ]);
    }
}
