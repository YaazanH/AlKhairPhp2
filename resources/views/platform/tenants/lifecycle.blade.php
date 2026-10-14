@if(auth('platform')->user()->hasPlatformPermission('manage.tenants'))
    <section class="platform-tenant-lifecycle rounded-3xl border bg-white p-6" data-tenant-lifecycle>
        <h2 class="text-xl font-bold">{{ __('platform-tenant.lifecycle') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('platform-tenant.lifecycle_help') }}</p>
        @php($provisioned = filled($tenant->database_name) && $tenant->domains->contains('is_primary', true))
        @if($tenant->status === 'provisioning_failed')<div class="mt-5 rounded-2xl border p-4"><h3 class="font-bold">{{ __('platform-tenant.failed') }}</h3><p class="mt-2 text-sm text-zinc-500">{{ __('platform-tenant.failed_help') }}</p></div>@endif
        @if($tenant->isOperational() || ($tenant->status === 'suspended' && $provisioned))
            @php($suspending = $tenant->isOperational())
            <details class="mt-5 rounded-2xl border p-4"><summary class="cursor-pointer font-semibold">{{ __('platform-tenant.'.($suspending ? 'suspend' : 'resume')) }}</summary><p class="mt-3 text-sm text-zinc-500">{{ __('platform-tenant.'.($suspending ? 'suspend_help' : 'resume_help')) }}</p>
                <form method="POST" action="{{ route('platform.tenants.status', $tenant) }}" class="mt-4 grid gap-3">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $suspending ? 'suspended' : 'active' }}"><label class="grid gap-2 text-sm">{{ __('platform-tenant.confirm', ['slug' => $tenant->slug]) }}<input name="confirm_slug" required pattern="{{ preg_quote($tenant->slug, '/') }}" autocomplete="off" dir="ltr" class="rounded-xl border p-3"></label><button class="w-fit rounded-xl border px-4 py-3 font-semibold">{{ __('platform-tenant.'.($suspending ? 'suspend' : 'resume')) }}</button></form>
            </details>
        @elseif($tenant->status === 'suspended')<p class="mt-5 text-sm text-zinc-500">{{ __('platform-tenant.unprovisioned') }}</p>@endif
        @if($tenant->status === 'suspended' && $provisioned && preg_match('/^alkhair_tenant_[a-f0-9]{32}$/', $tenant->database_name))
            <details class="platform-tenant-danger mt-4 rounded-2xl border p-4"><summary class="cursor-pointer font-semibold">{{ __('platform-tenant.delete') }}</summary><p class="mt-3 text-sm">{{ __('platform-tenant.delete_help') }}</p>@if($retentionEligibleAt)<p class="mt-2 text-sm text-zinc-500">{{ __('platform-tenant.retention', ['months' => $suspendedDataRetentionMonths, 'date' => $retentionEligibleAt->format('Y-m-d')]) }}</p>@endif
                <form method="POST" action="{{ route('platform.tenants.destroy', $tenant) }}" class="mt-4 grid gap-3">@csrf @method('DELETE')<label class="grid gap-2 text-sm">{{ __('platform-tenant.confirm', ['slug' => $tenant->slug]) }}<input name="confirm_slug" required pattern="{{ preg_quote($tenant->slug, '/') }}" autocomplete="off" dir="ltr" class="rounded-xl border p-3"></label><button class="w-fit rounded-xl border px-4 py-3 font-semibold">{{ __('platform-tenant.delete') }}</button></form>
            </details>
        @endif
    </section>
@endif
