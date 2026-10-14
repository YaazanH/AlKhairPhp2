<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\SaasBackupSetting;
use App\Models\Landlord\SaasPlatformSetting;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformSettingsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user('platform');
        $canViewSubscriptions = $user->hasPlatformPermission('view.subscriptions') || $user->hasPlatformPermission('manage.subscriptions');
        $canManageSubscriptions = $user->hasPlatformPermission('manage.subscriptions');
        $canManageBackups = $user->hasPlatformPermission('manage.backups');
        $canManageRequests = $user->hasPlatformPermission('manage.support.problems');
        $canManageMetrics = $user->isPlatformOwner();

        return view('platform.settings.index', [
            'settings' => $canViewSubscriptions || $canManageMetrics ? SaasPlatformSetting::current() : null,
            'backupSettings' => $canManageBackups ? SaasBackupSetting::current() : null,
            ...compact('canViewSubscriptions', 'canManageSubscriptions', 'canManageBackups', 'canManageRequests', 'canManageMetrics'),
        ]);
    }
}
