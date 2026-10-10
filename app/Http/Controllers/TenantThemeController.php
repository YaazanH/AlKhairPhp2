<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\Landlord\TenantContext;
use App\Support\TenantTheme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantThemeController extends Controller
{
    public function edit(Request $request, TenantContext $context, TenantTheme $theme): View
    {
        $this->authorizeTenantAdministrator($request, $context);

        return view('settings.theme', [
            'primaryColor' => $theme->primaryColor(),
            'colors' => $theme->colors(),
        ]);
    }

    public function update(Request $request, TenantContext $context, TenantTheme $theme): RedirectResponse
    {
        $this->authorizeTenantAdministrator($request, $context);
        $rules = collect(array_keys(TenantTheme::DEFAULT_COLORS))
            ->mapWithKeys(fn (string $key): array => [$key => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/']])
            ->all();
        $messages = collect(array_keys($rules))
            ->mapWithKeys(fn (string $key): array => [$key.'.regex' => __('theme.validation.format')])
            ->all();
        $data = $request->validate($rules, $messages);
        $candidate = array_replace($theme->colors(), array_map('strtolower', $data));
        $errors = $theme->readabilityErrors($candidate);

        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        foreach ($data as $key => $value) {
            AppSetting::storeValue('theme', $key, strtolower($value));
        }

        return back()->with('status', __('theme.saved'));
    }

    public function reset(Request $request, TenantContext $context): RedirectResponse
    {
        $this->authorizeTenantAdministrator($request, $context);
        AppSetting::query()->where('group', 'theme')->whereIn('key', array_keys(TenantTheme::DEFAULT_COLORS))->delete();

        return back()->with('status', __('theme.reset_done'));
    }

    private function authorizeTenantAdministrator(Request $request, TenantContext $context): void
    {
        abort_unless($context->hasTenant(), 404);
        $user = $request->user();
        abort_unless($user?->is_tenant_administrator === true || $user?->can('settings.manage'), 403);
    }
}
