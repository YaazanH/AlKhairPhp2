<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use Illuminate\Console\Command;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantProvisioningController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:80', Rule::notIn(config('tenancy.reserved_subdomains', [])), Rule::unique(Tenant::class, 'slug')],
            'owner_name' => ['required', 'string', 'max:255'], 'owner_email' => ['required', 'email'],
            'owner_password' => ['required', 'string', 'min:8'],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', Rule::in(array_keys(config('app.supported_locales', [])))],
            'plan' => [
                'required',
                Rule::in(Plan::query()->where('is_active', true)->pluck('code')->all()),
            ],
        ], [
            'slug.alpha_dash' => 'The subdomain may contain only letters, numbers, dashes, and underscores.',
            'slug.not_in' => 'This subdomain is reserved by the platform. Choose another one.',
            'slug.unique' => 'This subdomain is already assigned to another tenant.',
            'owner_password.min' => 'The temporary password must contain at least 8 characters.',
            'plan.in' => 'Choose an active package from the package list.',
        ], [
            'name' => 'organisation name',
            'slug' => 'subdomain',
            'owner_name' => 'tenant administrator name',
            'owner_email' => 'tenant administrator email',
            'owner_password' => 'temporary password',
            'plan' => 'initial package',
        ]);
        $exitCode = Artisan::call('saas:provision-tenant', [
            'name' => $data['name'], 'slug' => $data['slug'], 'owner-email' => $data['owner_email'],
            '--owner-name' => $data['owner_name'], '--owner-password' => $data['owner_password'], '--plan' => $data['plan'],
            '--platform-email' => $request->user('platform')->email,
            '--timezone' => $data['timezone'] ?? null, '--locale' => $data['locale'] ?? null,
        ]);

        if ($exitCode !== Command::SUCCESS) {
            $failure = Tenant::query()
                ->where('slug', Str::slug($data['slug']))
                ->first()?->provisioningAttempts()->latest('id')->value('error_message');

            return back()
                ->withInput($request->except('owner_password'))
                ->withErrors(['tenant' => $this->friendlyFailureMessage($failure)]);
        }

        return redirect()->route('platform.dashboard')->with('status', __('platform.provisioning.success'));
    }

    private function friendlyFailureMessage(?string $failure): string
    {
        $failure = strtolower((string) $failure);

        return match (true) {
            str_contains($failure, 'database exists') => 'Tenant setup stopped because an old database already exists for this tenant. Delete the failed tenant and try again.',
            str_contains($failure, 'access denied'), str_contains($failure, 'permission denied') => 'Tenant setup could not create or access its database. Check the configured MySQL permissions, then try again.',
            str_contains($failure, 'duplicate entry') && str_contains($failure, 'users_email') => 'Tenant setup found a conflicting administrator email. Use a different tenant administrator email or remove the failed tenant before retrying.',
            default => 'Tenant setup could not be completed. No usable tenant was activated. Open the failed tenant record for its status, then review the application log before retrying.',
        };
    }
}
