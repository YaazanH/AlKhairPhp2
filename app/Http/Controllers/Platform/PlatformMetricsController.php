<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Landlord\PlatformManagementMetrics;
use Illuminate\View\View;

class PlatformMetricsController extends Controller
{
    public function __invoke(PlatformManagementMetrics $metrics): View
    {
        return view('platform.metrics', [
            'managementMetrics' => $metrics->snapshot(),
        ]);
    }
}
