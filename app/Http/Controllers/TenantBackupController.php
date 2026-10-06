<?php

namespace App\Http\Controllers;

use App\Models\Landlord\TenantBackup;
use App\Services\Landlord\TenantBackupCoordinator;
use App\Services\Landlord\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantBackupController extends Controller
{
    public function index(TenantContext $context): View
    {
        $tenant = $context->tenant();

        return view('tenant.backups.index', [
            'tenant' => $tenant,
            'backups' => TenantBackup::query()->where('tenant_id', $tenant->id)->latest()->paginate(25),
        ]);
    }

    public function download(TenantBackup $tenantBackup, TenantContext $context): StreamedResponse
    {
        abort_unless($tenantBackup->tenant_id === $context->tenant()->id && $tenantBackup->isUsable(), 404);
        $disk = Storage::disk($tenantBackup->disk);
        abort_unless($disk->exists($tenantBackup->file_path), 404);

        return $disk->download($tenantBackup->file_path, $tenantBackup->filename, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function create(TenantContext $context, TenantBackupCoordinator $backups): RedirectResponse
    {
        try {
            $backups->create($context->tenant(), TenantBackup::TRIGGER_MANUAL);
        } catch (RuntimeException $exception) {
            report($exception);

            return back()->withErrors(['backup' => $this->creationFailureMessage($exception)]);
        }

        return redirect()->route('settings.tenant-backups')
            ->with('status', __('backups.tenant.messages.created'));
    }

    public function restore(Request $request, TenantBackup $tenantBackup, TenantContext $context, TenantBackupCoordinator $backups): RedirectResponse
    {
        $tenant = $context->tenant();
        abort_unless($tenantBackup->tenant_id === $tenant->id && $tenantBackup->isUsable(), 404);
        $request->validate([
            'password' => ['required', 'string'],
            'confirmation' => ['required', Rule::in([$tenant->slug])],
        ]);
        if (! Hash::check((string) $request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('backups.errors.password')]);
        }

        $backups->restore($tenant, $tenantBackup);

        return redirect()->route('settings.tenant-backups')->with('status', __('backups.tenant.messages.restored'));
    }

    private function creationFailureMessage(RuntimeException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'not recognized as an internal or external command')
            || str_contains($message, 'no such file or directory')) {
            return __('backups.tenant.errors.database_tool_missing');
        }

        if (str_contains($message, 'storage limit')
            || str_contains($message, 'no space left')
            || str_contains($message, 'disk full')) {
            return __('backups.tenant.errors.storage_full');
        }

        return __('backups.tenant.errors.create_failed');
    }
}
