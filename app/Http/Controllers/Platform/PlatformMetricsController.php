<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\SaasPlatformSetting;
use App\Services\Landlord\PlatformManagementMetrics;
use App\Services\Landlord\PlatformMetricHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PlatformMetricsController extends Controller
{
    public function index(Request $request, PlatformManagementMetrics $metrics, PlatformMetricHistory $history): View
    {
        $range = in_array($request->query('range'), PlatformMetricHistory::ALLOWED_RANGES, true)
            ? $request->query('range')
            : '24h';

        return view('platform.metrics', [
            'managementMetrics' => $metrics->snapshot(),
            'metricHistory' => $history->chart($range),
            'metricSettings' => SaasPlatformSetting::current(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'metrics_refresh_minutes' => ['required', 'integer', 'in:1,5,10,15,30'],
        ]);

        $settings = SaasPlatformSetting::current();
        $before = $settings->metrics_refresh_minutes;
        $settings->update($validated);

        PlatformAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'platform_administrator_id' => $request->user('platform')->id,
            'event' => 'platform_metrics_refresh_interval_updated',
            'properties' => [
                'before_minutes' => $before,
                'after_minutes' => $settings->metrics_refresh_minutes,
            ],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('status', __('platform.ui.metrics.settings_saved'));
    }
}
