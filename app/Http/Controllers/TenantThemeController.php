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
        ]);
    }

    public function update(Request $request, TenantContext $context, TenantTheme $theme): RedirectResponse
    {
        $this->authorizeTenantAdministrator($request, $context);
        $data = $request->validate([
            'primary_color' => [
                'required',
                'regex:/^#[0-9a-fA-F]{6}$/',
                function (string $attribute, mixed $value, \Closure $fail) use ($theme): void {
                    if (! $theme->canProduceReadablePalette((string) $value)) {
                        $fail(__('theme.validation.unreadable'));
                    }
                },
            ],
        ], ['primary_color.regex' => __('theme.validation.format')]);

        AppSetting::storeValue('theme', 'primary_color', strtolower($data['primary_color']));

        return back()->with('status', __('theme.saved'));
    }

    public function reset(Request $request, TenantContext $context): RedirectResponse
    {
        $this->authorizeTenantAdministrator($request, $context);
        AppSetting::query()->where('group', 'theme')->where('key', 'primary_color')->delete();

        return back()->with('status', __('theme.reset_done'));
    }

    private function authorizeTenantAdministrator(Request $request, TenantContext $context): void
    {
        abort_unless($context->hasTenant(), 404);
        $user = $request->user();
        abort_unless($user?->is_tenant_administrator === true || $user?->can('settings.manage'), 403);
    }
}
