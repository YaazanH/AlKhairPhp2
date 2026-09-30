<?php

namespace App\Http\Controllers;

use App\Services\Landlord\TenantContext;
use Illuminate\Http\RedirectResponse;

class BackupSettingsEntryController extends Controller
{
    public function __invoke(TenantContext $context): RedirectResponse
    {
        return redirect()->route($context->hasTenant() ? 'settings.tenant-backups' : 'settings.system-backups');
    }
}
