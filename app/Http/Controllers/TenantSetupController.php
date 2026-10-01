<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\Landlord\TenantContext;
use App\Services\Landlord\TenantSetupManager;
use App\Support\ApplicationTimezone;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantSetupController extends Controller
{
    public function show(Request $request, TenantContext $context, TenantSetupManager $setup, ApplicationTimezone $timezones): View
    {
        $this->authorizeManager($request);

        return view('tenant-setup.show', [
            'setup' => $setup->summary($context->tenant()),
            'settings' => AppSetting::groupValues('general'),
            'tenant' => $context->tenant(),
            'timezoneOptions' => $timezones->options(),
        ]);
    }

    public function foundation(Request $request, TenantContext $context, TenantSetupManager $setup): RedirectResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'school_name' => ['required', 'string', 'max:255'],
            'default_locale' => ['required', Rule::in(array_keys(config('app.supported_locales', [])))],
            'school_timezone' => ['required', 'string', 'max:100', 'timezone:all_with_bc'],
            'tenant_logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:'.config('uploads.image_max_kb')],
        ]);
        $summary = $setup->saveFoundation($context->tenant(), $data);
        if ($request->hasFile('tenant_logo')) {
            $tenant = $context->tenant();
            $previousPath = (string) AppSetting::groupValues('general')->get('tenant_logo_path');
            $path = $request->file('tenant_logo')->store('logo', 'public');
            AppSetting::storeValue('general', 'tenant_logo_path', $path);
            $tenant->update(['logo_path' => $path]);
            if ($previousPath && $previousPath !== $path) {
                Storage::disk('public')->delete($previousPath);
            }
        }
        $request->session()->put('locale', $data['default_locale']);
        $request->session()->put('locale_user_selected', true);
        $request->session()->put('tenant_setup_prompted_version', $summary['version']);

        return back()->with('status', __('onboarding.saved'));
    }

    public function module(Request $request, string $module, TenantContext $context, TenantSetupManager $setup): RedirectResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate(['status' => ['required', Rule::in(['ready', 'skipped'])]]);
        try {
            $summary = $setup->mark($context->tenant(), $module, $data['status']);
        } catch (DomainException $exception) {
            return back()->withErrors(['module' => $exception->getMessage()]);
        }
        $request->session()->put('tenant_setup_prompted_version', $summary['version']);

        return back()->with('status', __('onboarding.saved'));
    }

    public function finish(Request $request, TenantContext $context, TenantSetupManager $setup): RedirectResponse
    {
        $this->authorizeManager($request);
        try {
            $setup->skipOptional($context->tenant());
        } catch (DomainException $exception) {
            return back()->withErrors(['setup' => $exception->getMessage()]);
        }

        return redirect()->route('dashboard')->with('status', __('onboarding.complete'));
    }

    private function authorizeManager(Request $request): void
    {
        $user = $request->user();
        abort_unless($user?->canManageTenantSetup(), 403);
    }
}
