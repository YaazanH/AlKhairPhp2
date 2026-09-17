<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Console\Command;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;

class TenantProvisioningController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:80', Rule::notIn(config('tenancy.reserved_subdomains', []))],
            'owner_name' => ['required', 'string', 'max:255'], 'owner_email' => ['required', 'email'],
            'owner_password' => ['required', 'string', 'min:8'], 'plan' => ['required', 'in:core,core_finance,core_finance_printing'],
        ]);
        $exitCode = Artisan::call('saas:provision-tenant', [
            'name' => $data['name'], 'slug' => $data['slug'], 'owner-email' => $data['owner_email'],
            '--owner-name' => $data['owner_name'], '--owner-password' => $data['owner_password'], '--plan' => $data['plan'],
            '--platform-email' => $request->user('platform')->email,
        ]);

        if ($exitCode !== Command::SUCCESS) {
            return back()
                ->withInput($request->except('owner_password'))
                ->withErrors(['tenant' => __('platform.provisioning.failed')]);
        }

        return redirect()->route('platform.dashboard')->with('status', __('platform.provisioning.success'));
    }
}
