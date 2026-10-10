<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\SaasPlatformSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PlatformSubscriptionSettingController extends Controller
{
    public function edit(): View
    {
        return view('platform.settings.subscriptions', [
            'settings' => SaasPlatformSetting::current(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'suspended_data_retention_months' => ['required', 'integer', 'between:1,120'],
        ]);
        $settings = SaasPlatformSetting::current();
        $before = $settings->suspended_data_retention_months;
        $settings->update($data);

        PlatformAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'platform_administrator_id' => $request->user('platform')->id,
            'event' => 'subscription_retention_setting_updated',
            'properties' => [
                'before_months' => $before,
                'after_months' => $settings->suspended_data_retention_months,
            ],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('status', 'Subscription retention settings saved.');
    }
}
