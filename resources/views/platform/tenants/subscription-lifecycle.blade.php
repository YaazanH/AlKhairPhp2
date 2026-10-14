@if($canManageSubscriptions && $tenant->subscription)
    <section class="rounded-3xl border bg-white p-6" data-subscription-lifecycle>
        <h2 class="text-xl font-bold">{{ __('platform-tenant.subscription_lifecycle') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('platform-tenant.subscription_status') }}: {{ __('platform-tenant.statuses.'.$tenant->subscription->status) }}</p>
        @php($reactivating = in_array($tenant->subscription->status, ['cancelled','expired','suspended'], true))
        @php($canReactivate = filled($tenant->database_name) && $tenant->domains->contains('is_primary', true) && $tenant->subscription->plan)
        @if(($reactivating && $canReactivate) || (!$reactivating && $tenant->subscription->ends_at))
            <details class="mt-5 rounded-2xl border p-4"><summary class="cursor-pointer font-semibold">{{ __('platform-tenant.'.($reactivating ? 'reactivate' : 'cancel_subscription')) }}</summary><p class="mt-3 text-sm text-zinc-500">{{ __('platform-tenant.'.($reactivating ? 'reactivate_help' : 'cancel_help')) }}</p>
                <form method="POST" action="{{ route($reactivating ? 'platform.tenants.subscription.reactivate' : 'platform.tenants.subscription.cancel', $tenant) }}" class="mt-4 grid gap-3">@csrf<label class="grid gap-2 text-sm">{{ __('platform-tenant.confirm', ['slug' => $tenant->slug]) }}<input name="confirm_slug" required pattern="{{ preg_quote($tenant->slug, '/') }}" dir="ltr" autocomplete="off" class="rounded-xl border p-3"></label><button class="w-fit rounded-xl border px-4 py-3 font-semibold">{{ __('platform-tenant.'.($reactivating ? 'reactivate' : 'cancel_subscription')) }}</button></form>
            </details>
        @endif
    </section>
@endif
