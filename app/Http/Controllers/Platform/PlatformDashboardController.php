<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformMetricSnapshot;
use App\Models\Landlord\PlatformSubscriptionLedgerEntry;
use App\Models\Landlord\PlatformSupportCase;
use App\Models\Landlord\SaasPlatformSetting;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantBackup;
use App\Models\Landlord\TenantSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformDashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user('platform');
        // Preserve old filtered directory bookmarks without making home a list again.
        if ($request->hasAny(['search', 'status']) && ($user->hasPlatformPermission('view.tenants') || $user->hasPlatformPermission('manage.tenants'))) {
            return redirect()->route('platform.tenants.index', $request->only(['search', 'status']));
        }
        $range = in_array($request->query('range'), ['7d', '30d', '90d'], true) ? $request->query('range') : '30d';
        $since = now()->subDays((int) $range);
        $canViewBilling = $user->hasPlatformPermission('view.subscriptions') || $user->hasPlatformPermission('manage.subscriptions');
        $finance = null;
        if ($canViewBilling) {
            $entries = PlatformSubscriptionLedgerEntry::query();
            $balances = (clone $entries)->selectRaw('tenant_id, SUM(credit_syp) - SUM(debit_syp) AS balance')->groupBy('tenant_id')->get();
            $finance = [
                'payments' => (clone $entries)->where('type', PlatformSubscriptionLedgerEntry::TYPE_OFFLINE_PAYMENT)->whereBetween('paid_at', [$since, now()])->sum('credit_syp'),
                'charges' => (clone $entries)->whereIn('type', [PlatformSubscriptionLedgerEntry::TYPE_ACTIVATION, PlatformSubscriptionLedgerEntry::TYPE_RENEWAL])->whereBetween('created_at', [$since, now()])->sum('debit_syp'),
                'outstanding' => $balances->sum(fn ($row) => max(0, -(int) $row->balance)),
                'ending' => TenantSubscription::query()->whereIn('status', ['active', 'trial'])->whereBetween('ends_at', [now(), now()->addDays(7)])->count(),
            ];
        }
        $types = [];
        if ($user->hasPlatformPermission('manage.support.problems')) {
            $types[] = 'problem';
        }
        if ($user->hasPlatformPermission('manage.support.suggestions')) {
            $types[] = 'suggestion';
        }
        $requests = $types ? PlatformSupportCase::query()->whereIn('type', $types)->whereIn('status', PlatformSupportCase::attentionStatuses())->count() : null;
        $backups = $user->hasPlatformPermission('view.backups') ? [
            'failed' => TenantBackup::query()->where('status', TenantBackup::STATUS_FAILED)->where('updated_at', '>=', now()->subDay())->count(),
            'latest' => TenantBackup::query()->where('status', TenantBackup::STATUS_COMPLETED)->whereNotNull('verified_at')->max('verified_at'),
        ] : null;

        $snapshot = PlatformMetricSnapshot::query()->where('resolution', 'interval')->latest('captured_at')->first();
        $refreshMinutes = (int) (SaasPlatformSetting::query()->value('metrics_refresh_minutes') ?? 5);

        return view('platform.dashboard', [
            ...compact('range', 'finance', 'requests', 'backups'),
            'snapshot' => $snapshot,
            'snapshotStale' => $snapshot && $snapshot->captured_at->lt(now()->subMinutes(max(5, $refreshMinutes * 2))),
            'activity' => $user->isPlatformOwner() ? PlatformAuditEvent::query()->with('platformAdministrator')->latest()->limit(6)->get() : collect(),
            'tenantStatusCounts' => Tenant::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }
}
