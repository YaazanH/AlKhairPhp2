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
    public function index(): View
    {
        return view('platform.backups.index', [
            'settings' => SaasBackupSetting::current(),
            'backups' => TenantBackup::query()->with('tenant')->latest()->paginate(25),
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name', 'slug']),
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
        $request->validate(['confirmation' => ['required', Rule::in([$tenantBackup->tenant->slug])]]);
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
