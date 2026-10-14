<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\SaasBackupSetting;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantBackup;
use App\Services\Landlord\TenantBackupCoordinator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantBackupController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $state = (string) $request->query('state', 'all');
        $trigger = (string) $request->query('trigger', 'all');
        $query = TenantBackup::query()->with('tenant')
            ->when($search !== '', fn ($query) => $query->whereHas('tenant', fn ($tenant) => $tenant
                ->where('name', 'like', '%'.$search.'%')->orWhere('slug', 'like', '%'.$search.'%')))
            ->when($request->filled('tenant'), fn ($query) => $query->where('tenant_id', $request->query('tenant')));
        $summaryQuery = clone $query;
        $query->when($state === 'verified', fn ($query) => $query->where('status', TenantBackup::STATUS_COMPLETED)->whereNotNull('verified_at')->whereNotNull('file_path')->where('file_path', '!=', ''))
            ->when(in_array($state, ['creating', 'completed', 'failed'], true), fn ($query) => $query->where('status', $state))
            ->when(in_array($trigger, ['manual', 'scheduled', 'pre_restore'], true), fn ($query) => $query->where('trigger', $trigger));

        return view('platform.backups.index', [
            'settings' => SaasBackupSetting::current(),
            'backups' => $query->latest()->orderByDesc('id')->paginate(20)->withQueryString(),
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name', 'slug', 'status', 'database_name']),
            'backupStats' => [
                'verified' => (clone $summaryQuery)->where('status', TenantBackup::STATUS_COMPLETED)->whereNotNull('verified_at')->whereNotNull('file_path')->where('file_path', '!=', '')->count(),
                'failed' => (clone $summaryQuery)->where('status', TenantBackup::STATUS_FAILED)->count(),
                'creating' => (clone $summaryQuery)->where('status', TenantBackup::STATUS_CREATING)->count(),
            ],
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'run_at' => ['required', 'date_format:H:i'],
            'retention_count' => ['required', 'integer', 'between:1,365'],
        ]);

        SaasBackupSetting::current()->update([
            'is_enabled' => (bool) ($data['is_enabled'] ?? false),
            'run_at' => $data['run_at'].':00',
            'retention_count' => $data['retention_count'],
        ]);

        return back()->with('status', 'SaaS backup settings saved.');
    }

    public function create(Request $request, TenantBackupCoordinator $backups): RedirectResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'integer']]);
        $tenant = Tenant::query()->findOrFail($data['tenant_id']);
        if (! $tenant->isOperational() || blank($tenant->database_name)) {
            return back()->withErrors(['tenant_id' => __('platform-operations.operational_required')])->withInput();
        }
        $backup = $backups->create($tenant, TenantBackup::TRIGGER_MANUAL);
        $this->audit($request, $tenant, 'tenant_backup_created', ['backup_uuid' => $backup->uuid]);

        return back()->with('status', "Backup created for {$tenant->name}.");
    }

    public function download(TenantBackup $tenantBackup): StreamedResponse
    {
        abort_unless($tenantBackup->isUsable(), 404);
        $disk = Storage::disk($tenantBackup->disk);
        abort_unless($disk->exists($tenantBackup->file_path), 404);

        return $disk->download($tenantBackup->file_path, $tenantBackup->filename, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function restore(Request $request, TenantBackup $tenantBackup, TenantBackupCoordinator $backups): RedirectResponse
    {
        $tenantBackup->load('tenant');
        abort_unless($tenantBackup->tenant && $tenantBackup->isUsable(), 422);
        $request->validate(['confirmation' => ['required', Rule::in([$tenantBackup->tenant->slug])]]);
        if (! $tenantBackup->tenant->isOperational() || blank($tenantBackup->tenant->database_name)) {
            return back()->withErrors(['confirmation' => __('platform-operations.operational_required')])->withInput();
        }
        $backups->restore($tenantBackup->tenant, $tenantBackup);
        $this->audit($request, $tenantBackup->tenant, 'tenant_backup_restored', ['backup_uuid' => $tenantBackup->uuid]);

        return redirect()->route('platform.backups.index')->with('status', "Backup restored for {$tenantBackup->tenant->name}.");
    }

    private function audit(Request $request, Tenant $tenant, string $event, array $properties): void
    {
        PlatformAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'platform_administrator_id' => $request->user('platform')->id,
            'tenant_id' => $tenant->id,
            'event' => $event,
            'properties' => $properties,
            'ip_address' => $request->ip(),
        ]);
    }
}
