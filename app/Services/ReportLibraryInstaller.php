<?php

namespace App\Services;

use App\Models\Landlord\PlatformReportLibraryItem;
use App\Models\Landlord\PlatformReportLibraryRevision;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Services\Landlord\CurrentModuleAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportLibraryInstaller
{
    public function __construct(
        private readonly ReportDesignerCatalog $catalog,
        private readonly ReportConditionService $conditions,
        private readonly ReportRelationshipCatalog $relationships,
        private readonly CurrentModuleAccess $modules,
    ) {}

    public function compatibility(PlatformReportLibraryItem $item, User $user): array
    {
        $revision = $item->publishedRevision;

        if (! $revision) {
            return ['compatible' => false, 'reason' => __('report_library.compatibility.unpublished')];
        }

        $missingModules = collect($revision->required_modules)
            ->reject(fn (string $module): bool => $this->modules->enabled($module))
            ->values();

        if ($missingModules->isNotEmpty()) {
            return [
                'compatible' => false,
                'reason' => __('report_library.compatibility.missing_modules', [
                    'modules' => $missingModules->map(fn (string $module): string => (string) config('modules.definitions.'.$module.'.name', str_replace('_', ' ', $module)))->join(', '),
                ]),
            ];
        }

        $source = (string) data_get($revision->definition, 'data_source');
        if (! array_key_exists($source, $this->catalog->sources($user))) {
            return ['compatible' => false, 'reason' => __('report_library.compatibility.source_permission')];
        }

        try {
            $this->normalizedDefinition($revision);
        } catch (ValidationException) {
            return ['compatible' => false, 'reason' => __('report_library.compatibility.outdated')];
        }

        return ['compatible' => true, 'reason' => null];
    }

    public function install(PlatformReportLibraryItem $item, User $user): ReportDefinition
    {
        $compatibility = $this->compatibility($item, $user);
        if (! $compatibility['compatible']) {
            throw ValidationException::withMessages(['library_item' => $compatibility['reason']]);
        }

        $revision = $item->publishedRevision;
        $definition = $this->normalizedDefinition($revision);
        $baseName = $this->localized($revision->name);

        return DB::transaction(function () use ($item, $revision, $definition, $baseName, $user): ReportDefinition {
            return ReportDefinition::query()->create([
                'name' => $this->uniqueName($baseName),
                'description' => $this->localized($revision->description ?? []),
                ...$definition,
                'status' => ReportDefinition::STATUS_DRAFT,
                'library_item_uuid' => $item->uuid,
                'library_revision' => $revision->version,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        });
    }

    private function normalizedDefinition(PlatformReportLibraryRevision $revision): array
    {
        $definition = $revision->definition;
        $source = (string) data_get($definition, 'data_source');
        $fields = $this->catalog->validateFields($source, (array) data_get($definition, 'selected_fields', []));
        $groupBy = $this->catalog->validateGrouping($source, data_get($definition, 'group_by'));
        $calculations = $this->catalog->validateCalculations($source, (array) data_get($definition, 'calculations', []));
        [$sortField, $sortDirection] = $this->catalog->validateSort(
            $source,
            data_get($definition, 'sort_field'),
            (string) data_get($definition, 'sort_direction', 'asc'),
        );

        $filters = (array) data_get($definition, 'filters', []);
        $filters['condition_tree'] = $this->conditions->validate($source, $filters['condition_tree'] ?? []);
        $relationships = $this->relationships->resolve(
            $source,
            data_get($definition, 'relationships'),
            $this->relationships->usedFields($definition + ['filters' => $filters]),
        );

        return [
            'data_source' => $source,
            'relationships' => $relationships,
            'selected_fields' => $fields,
            'calculations' => $calculations,
            'group_by' => $groupBy,
            'presentation' => $this->catalog->validatePresentation((array) data_get($definition, 'presentation', []), $groupBy, true, $source, $calculations),
            'filters' => $filters,
            'sort_field' => $sortField,
            'sort_direction' => $sortDirection,
        ];
    }

    private function localized(array $values): ?string
    {
        $value = $values[app()->getLocale()] ?? $values['en'] ?? $values['ar'] ?? null;

        return filled($value) ? (string) $value : null;
    }

    private function uniqueName(string $baseName): string
    {
        $name = $baseName;
        $suffix = 2;

        while (ReportDefinition::query()->where('name', $name)->exists()) {
            $name = $baseName.' ('.$suffix++.')';
        }

        return $name;
    }
}
