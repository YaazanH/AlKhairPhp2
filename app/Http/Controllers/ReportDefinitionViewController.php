<?php

namespace App\Http\Controllers;

use App\Exceptions\ReportQueryTimeoutException;
use App\Models\ReportDefinition;
use App\Services\ReportDefinitionAccess;
use App\Services\ReportDesignerCatalog;
use App\Services\ReportDesignerQueryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportDefinitionViewController extends Controller
{
    public function __invoke(Request $request, ReportDefinition $reportDefinition, ReportDefinitionAccess $access): View
    {
        abort_unless($access->canView($request->user(), $reportDefinition), 404);

        try {
            $result = app(ReportDesignerQueryService::class)->preview([
                'data_source' => $reportDefinition->data_source,
                'relationships' => $reportDefinition->relationships,
                'relationship_modes' => $reportDefinition->relationship_modes,
                'selected_fields' => $reportDefinition->selected_fields,
                'calculations' => $reportDefinition->calculations ?? [],
                'group_by' => $reportDefinition->group_by,
                'filters' => $reportDefinition->filters ?? [],
                'sort_field' => $reportDefinition->sort_field,
                'sort_direction' => $reportDefinition->sort_direction,
            ], $request->user());
        } catch (ReportQueryTimeoutException $exception) {
            abort(422, $exception->userMessage());
        }

        return view('reports.designer-show', [
            'definition' => $reportDefinition,
            'result' => $result,
            'source' => app(ReportDesignerCatalog::class)->sources($request->user())[$reportDefinition->data_source],
        ]);
    }
}
