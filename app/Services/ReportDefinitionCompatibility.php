<?php

namespace App\Services;

use App\Models\ReportDefinition;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReportDefinitionCompatibility
{
    public function __construct(
        protected ReportDesignerCatalog $catalog,
        protected ReportConditionService $conditions,
        protected ReportRelationshipCatalog $relationships,
    ) {}

    /**
     * @return array{compatible: bool, visible: bool, reason: ?string}
     */
    public function inspect(ReportDefinition $definition, ?User $user): array
    {
        if (! array_key_exists($definition->data_source, $this->catalog->sources($user))) {
            $hiddenByUserPermission = array_key_exists(
                $definition->data_source,
                $this->catalog->sources(),
            );

            return [
                'compatible' => false,
                'visible' => ! $hiddenByUserPermission,
                'reason' => __('report_designer.compatibility.source_unavailable'),
            ];
        }

        try {
            $this->catalog->validateFields($definition->data_source, $definition->selected_fields ?? []);
            $calculations = $this->catalog->validateCalculations($definition->data_source, $definition->calculations ?? []);
            $groupBy = $this->catalog->validateGrouping($definition->data_source, $definition->group_by);
            $this->catalog->validateSort(
                $definition->data_source,
                $definition->sort_field,
                $definition->sort_direction,
            );
            $this->catalog->validatePresentation(
                $definition->presentation ?? [],
                $groupBy,
                true,
                $definition->data_source,
                $calculations,
            );
            $snapshot = $definition->only(ReportVersionService::VERSIONED_FIELDS);
            $relationships = $this->relationships->resolve(
                $definition->data_source,
                $definition->relationships,
                $this->relationships->usedFields($snapshot),
            );
            $this->relationships->validateModes(
                $definition->data_source,
                $relationships,
                $definition->relationship_modes,
            );
            $this->conditions->validate(
                $definition->data_source,
                data_get($definition->filters, 'condition_tree', []),
                $relationships,
            );

        } catch (ValidationException) {
            return [
                'compatible' => false,
                'visible' => true,
                'reason' => __('report_designer.compatibility.definition_outdated'),
            ];
        }

        return ['compatible' => true, 'visible' => true, 'reason' => null];
    }

    public function isCompatible(ReportDefinition $definition, ?User $user): bool
    {
        return $this->inspect($definition, $user)['compatible'];
    }
}
