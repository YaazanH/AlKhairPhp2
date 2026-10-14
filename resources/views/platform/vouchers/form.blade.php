<form data-voucher-form method="POST" action="{{ route('platform.vouchers.store') }}" class="mt-5 space-y-5">
                @csrf
                <p class="text-sm text-zinc-500">{{ __('platform-billing.terms_help') }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">{{ __('platform-billing.code') }}<input name="code" required maxlength="80" value="{{ old('code') }}" placeholder="WELCOME20" class="rounded-xl border p-3 uppercase"></label>
                    <label class="grid gap-1 text-sm">{{ __('platform-billing.name') }}<input name="name" required maxlength="255" value="{{ old('name') }}" class="rounded-xl border p-3"></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">{{ __('platform-billing.discount_type') }}<select name="discount_type" class="rounded-xl border p-3"><option value="percent" @selected(old('discount_type') === 'percent')>{{ __('platform-billing.percent') }}</option><option value="fixed" @selected(old('discount_type') === 'fixed')>{{ __('platform-billing.fixed') }}</option></select></label>
                    <label class="grid gap-1 text-sm">{{ __('platform-billing.discount_value') }}<input name="discount_value" type="number" min="1" required value="{{ old('discount_value') }}" class="rounded-xl border p-3"><span class="text-xs text-zinc-500">{{ __('platform-billing.percent_help') }}</span></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">{{ __('platform-billing.scope') }}<select name="usage_limit" class="rounded-xl border p-3"><option value="unlimited" @selected(old('usage_limit') === 'unlimited')>{{ __('platform-billing.unlimited') }}</option><option value="one" @selected(old('usage_limit') === 'one')>{{ __('platform-billing.one') }}</option><option value="total" @selected(old('usage_limit') === 'total')>{{ __('platform-billing.total') }}</option><option value="tenant" @selected(old('usage_limit') === 'tenant')>{{ __('platform-billing.tenant') }}</option></select></label>
                    <label class="grid gap-1 text-sm">{{ __('platform-billing.max_uses') }}<input data-voucher-total name="max_redemptions" type="number" min="2" value="{{ old('max_redemptions') }}" class="rounded-xl border p-3"><span class="text-xs text-zinc-500">{{ __('platform-billing.max_help') }}</span></label>
                </div>
                <label class="grid gap-1 text-sm">{{ __('platform-billing.selected_tenant') }}<select data-voucher-tenant name="tenant_id" class="rounded-xl border p-3"><option value="">{{ __('platform-billing.choose_tenant') }}</option>@foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected((string) old('tenant_id') === (string) $tenant->id)>{{ $tenant->name }} · {{ $tenant->slug }}</option>@endforeach</select><span class="text-xs text-zinc-500">{{ __('platform-billing.tenant_help') }}</span></label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">{{ __('platform-billing.application') }}<select name="application_type" class="rounded-xl border p-3"><option value="first_period" @selected(old('application_type') === 'first_period')>{{ __('platform-billing.first_period') }}</option><option value="limited_periods" @selected(old('application_type') === 'limited_periods')>{{ __('platform-billing.limited_periods') }}</option><option value="recurring" @selected(old('application_type') === 'recurring')>{{ __('platform-billing.recurring') }}</option></select></label>
                    <label class="grid gap-1 text-sm">{{ __('platform-billing.periods') }}<input data-voucher-periods name="max_uses_per_subscription" type="number" min="1" max="120" value="{{ old('max_uses_per_subscription') }}" class="rounded-xl border p-3"><span class="text-xs text-zinc-500">{{ __('platform-billing.period_help') }}</span></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">{{ __('platform-billing.starts') }} <span class="text-xs text-zinc-500">{{ __('platform-billing.optional') }}</span><input name="starts_at" type="datetime-local" value="{{ old('starts_at') }}" class="rounded-xl border p-3"></label>
                    <label class="grid gap-1 text-sm">{{ __('platform-billing.ends') }} <span class="text-xs text-zinc-500">{{ __('platform-billing.optional') }}</span><input name="ends_at" type="datetime-local" value="{{ old('ends_at') }}" class="rounded-xl border p-3"></label>
                </div>
                <button class="w-full rounded-xl bg-emerald-700 px-4 py-3 font-medium text-white">{{ __('platform-billing.create') }}</button>
            </form>
