<?php

namespace App\Services;

use App\Models\ReportDefinition;
use App\Models\ReportDefinitionRevision;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ReportVersionService
{
    public const VERSIONED_FIELDS = [
        'name',
        'description',
        'data_source',
        'relationships',
        'relationship_modes',
        'selected_fields',
        'calculations',
        'group_by',
        'presentation',
        'filters',
        'sort_field',
        'sort_direction',
    ];

    public function __construct(
        private readonly ReportDesignerCatalog $catalog,
        private readonly ReportConditionService $conditions,
        private readonly ReportRelationshipCatalog $relationships,
    ) {}

    public function capture(
        ReportDefinition $definition,
        string $action,
        ?User $actor = null,
        ?int $restoredFromRevision = null,
    ): ?ReportDefinitionRevision {
        if (! Schema::hasTable('report_definition_revisions')) {
            return null;
        }

        return $definition->revisions()->create([
            'revision_number' => ((int) $definition->revisions()->max('revision_number')) + 1,
            'action' => $action,
            'snapshot' => $this->snapshot($definition),
            'restored_from_revision_number' => $restoredFromRevision,
            'created_by' => $actor?->id ?? Auth::id() ?? $definition->updated_by,
        ]);
    }

    public function restore(
        ReportDefinition $definition,
        ReportDefinitionRevision $source,
        User $actor,
    ): ReportDefinitionRevision {
        abort_unless($source->report_definition_id === $definition->id, 404);
        $restored = $this->validatedSnapshot($source->snapshot, $actor);
        $before = $this->snapshot($definition);

        return DB::transaction(function () use ($definition, $source, $actor, $restored, $before): ReportDefinitionRevision {
            $definition->forceFill($restored + ['updated_by' => $actor->id])->saveQuietly();
            $revision = $this->capture($definition, 'restored', $actor, $source->revision_number);

            app(ReportAuditService::class)->revisionRestored(
                $definition,
                $before,
                $this->snapshot($definition),
                $source->revision_number,
                $revision?->revision_number,
            );

            return $revision;
        });
    }

    public function snapshot(ReportDefinition $definition): array
    {
        return collect(self::VERSIONED_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => $definition->getAttribute($field)])
            ->all();
    }

    private function validatedSnapshot(array $snapshot, User $actor): array
    {
        $source = (string) ($snapshot['data_source'] ?? '');
        if (! array_key_exists($source, $this->catalog->sources($actor))) {
            throw ValidationException::withMessages([
                'revision' => __('report_designer.validation.revision_source_unavailable'),
            ]);
        }

        $fields = $this->catalog->validateFields($source, (array) ($snapshot['selected_fields'] ?? []));
        $calculations = $this->catalog->validateCalculations($source, (array) ($snapshot['calculations'] ?? []));
        $groupBy = $this->catalog->validateGrouping($source, $snapshot['group_by'] ?? null);
        [$sortField, $sortDirection] = $this->catalog->validateSort(
            $source,
            $snapshot['sort_field'] ?? null,
            (string) ($snapshot['sort_direction'] ?? 'asc'),
        );

        $filters = (array) ($snapshot['filters'] ?? []);
        $relationships = $this->relationships->resolve(
            $source,
            $snapshot['relationships'] ?? null,
            $this->relationships->usedFields($snapshot),
        );
        $filters['condition_tree'] = $this->conditions->validate(
            $source,
            $filters['condition_tree'] ?? [],
            $relationships,
        );
        $relationshipModes = $this->relationships->validateModes(
            $source,
            $relationships,
            $snapshot['relationship_modes'] ?? null,
        );

        return [
            'name' => (string) ($snapshot['name'] ?? ''),
            'description' => filled($snapshot['description'] ?? null) ? (string) $snapshot['description'] : null,
            'data_source' => $source,
            'relationships' => $relationships,
            'relationship_modes' => $relationshipModes,
            'selected_fields' => $fields,
            'calculations' => $calculations,
            'group_by' => $groupBy,
            'presentation' => $this->catalog->validatePresentation(
                (array) ($snapshot['presentation'] ?? []),
                $groupBy,
                true,
                $source,
                $calculations,
            ),
            'filters' => $filters,
            'sort_field' => $sortField,
            'sort_direction' => $sortDirection,
        ];
    }
}
