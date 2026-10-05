<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
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
            'storage_limit_gb' => ['required', 'numeric', 'min:0.1', 'max:100000'],
        ], [
            'slug.alpha_dash' => 'The subdomain may contain only letters, numbers, dashes, and underscores.',
            'slug.not_in' => 'This subdomain is reserved by the platform. Choose another one.',
            'slug.unique' => 'This subdomain is already assigned to another tenant.',
            'owner_password.min' => 'The temporary password must contain at least 8 characters.',
        ], [
            'name' => 'organisation name',
            'slug' => 'subdomain',
            'owner_name' => 'tenant administrator name',
            'owner_email' => 'tenant administrator email',
            'owner_password' => 'temporary password',
            'storage_limit_gb' => 'tenant storage limit',
        ]);
        $exitCode = Artisan::call('saas:provision-tenant', [
            'name' => $data['name'], 'slug' => $data['slug'], 'owner-email' => $data['owner_email'],
            '--owner-name' => $data['owner_name'], '--owner-password' => $data['owner_password'],
            '--platform-email' => $request->user('platform')->email,
            '--storage-limit-gb' => $data['storage_limit_gb'],
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

        $tenant = Tenant::query()->where('slug', Str::slug($data['slug']))->first();

        return redirect()->route($tenant ? 'platform.tenants.edit' : 'platform.dashboard', $tenant ? [$tenant] : [])
            ->with('status', 'Tenant workspace created. Add credit and select a package when you are ready to activate it.');
    }

    private function friendlyFailureMessage(?string $failure): string
    {
        $failure = strtolower((string) $failure);

        return match (true) {
            str_contains($failure, 'cleanup also failed') => 'Tenant setup failed and some temporary resources could not be removed automatically. Do not retry yet; review the application log and clean the failed tenant resources first.',
            str_contains($failure, 'database exists') => 'Tenant setup stopped because an old database already exists. That existing database was left untouched. Inspect it before deleting the failed tenant or trying again.',
            str_contains($failure, 'access denied'), str_contains($failure, 'permission denied') => 'Tenant setup could not create or access its database. Check the configured MySQL permissions, then try again.',
            str_contains($failure, 'duplicate entry') && str_contains($failure, 'users_email') => 'Tenant setup found a conflicting administrator email. Use a different tenant administrator email or remove the failed tenant before retrying.',
            default => 'Tenant setup could not be completed. The database and files created by this attempt were rolled back safely. The failed record remains available for diagnosis before you delete it and try again.',
        };
    }
}
