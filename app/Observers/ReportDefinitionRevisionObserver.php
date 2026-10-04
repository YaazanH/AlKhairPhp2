<?php

namespace App\Observers;

use App\Models\ReportDefinition;
use App\Services\ReportVersionService;

class ReportDefinitionRevisionObserver
{
    public function created(ReportDefinition $definition): void
    {
        app(ReportVersionService::class)->capture($definition, 'created');
    }

    public function updated(ReportDefinition $definition): void
    {
        if (array_intersect(array_keys($definition->getChanges()), ReportVersionService::VERSIONED_FIELDS) === []) {
            return;
        }

        app(ReportVersionService::class)->capture($definition, 'updated');
    }
}
