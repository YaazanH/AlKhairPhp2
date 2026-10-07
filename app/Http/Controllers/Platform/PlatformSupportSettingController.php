<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\SaasPlatformSetting;
use App\Services\Landlord\SupportRequestConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlatformSupportSettingController extends Controller
{
    public function edit(SupportRequestConfiguration $configuration): View
    {
        return view('platform.support.settings', [
            'options' => $configuration->raw(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'support_request_options' => ['required', 'array'],
            'support_request_options.*' => ['required', 'array', 'min:1'],
            'support_request_options.*.*.key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'support_request_options.*.*.label_en' => ['required', 'string', 'max:180'],
            'support_request_options.*.*.label_ar' => ['required', 'string', 'max:180'],
            'support_request_options.*.*.enabled' => ['nullable', 'boolean'],
        ]);

        $options = collect([
            SupportRequestConfiguration::REASONS,
            SupportRequestConfiguration::PRIORITIES,
            SupportRequestConfiguration::IMPACTS,
        ])->mapWithKeys(function (string $group) use ($data): array {
            $rows = collect($data['support_request_options'][$group] ?? [])->map(fn (array $row): array => [
                'key' => $row['key'],
                'label_en' => trim($row['label_en']),
                'label_ar' => trim($row['label_ar']),
                'enabled' => (bool) ($row['enabled'] ?? false),
            ])->values();

            if ($rows->pluck('key')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'support_request_options.'.$group => __('support.settings.unique_keys'),
                ]);
            }

            if (! $rows->contains('enabled', true)) {
                throw ValidationException::withMessages([
                    'support_request_options.'.$group => __('support.settings.enabled_required'),
                ]);
            }

            return [$group => $rows->all()];
        })->all();

        $settings = SaasPlatformSetting::current();
        $before = $settings->support_request_options;
        $settings->update(['support_request_options' => $options]);

        PlatformAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'platform_administrator_id' => $request->user('platform')->id,
            'event' => 'support_request_settings_updated',
            'properties' => ['before' => $before, 'after' => $options],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('status', __('support.messages.settings_updated'));
    }
}
