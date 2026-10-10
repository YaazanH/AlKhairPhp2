<x-platform-layout title="Subscription vouchers">
    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <p class="text-sm font-semibold text-emerald-700">Subscriptions</p>
            <h1 class="mt-2 text-3xl font-bold">Vouchers and discounts</h1>
            <p class="mt-2 max-w-2xl text-zinc-600">Create controlled SYP or percentage discounts. Each renewal charge can use only the single voucher assigned to its subscription.</p>
        </div>
        <a href="{{ route('platform.subscription-settings.edit') }}" class="text-sm font-medium text-emerald-700">Back to subscription settings</a>
    </header>

    <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
        @if(auth('platform')->user()->hasPlatformPermission('manage.subscriptions'))
            <form method="POST" action="{{ route('platform.vouchers.store') }}" class="space-y-4 rounded-3xl border bg-white p-6 shadow-sm">
                @csrf
                <div><h2 class="font-semibold">Create voucher</h2><p class="mt-1 text-sm text-zinc-500">Voucher terms cannot be edited after use. Deactivate a voucher and create a replacement when its terms need to change.</p></div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">Code<input name="code" required maxlength="80" value="{{ old('code') }}" placeholder="WELCOME20" class="rounded-xl border p-3 uppercase"></label>
                    <label class="grid gap-1 text-sm">Internal name<input name="name" required maxlength="255" value="{{ old('name') }}" class="rounded-xl border p-3"></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">Discount type<select name="discount_type" class="rounded-xl border p-3"><option value="percent" @selected(old('discount_type') === 'percent')>Percentage</option><option value="fixed" @selected(old('discount_type') === 'fixed')>Fixed amount in SYP</option></select></label>
                    <label class="grid gap-1 text-sm">Discount value<input name="discount_value" type="number" min="1" required value="{{ old('discount_value') }}" class="rounded-xl border p-3"><span class="text-xs text-zinc-500">Percent values cannot exceed 100.</span></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">Who may use it<select name="usage_limit" class="rounded-xl border p-3"><option value="unlimited" @selected(old('usage_limit') === 'unlimited')>Any tenant, unlimited total uses</option><option value="one" @selected(old('usage_limit') === 'one')>One use in total</option><option value="total" @selected(old('usage_limit') === 'total')>Specified total uses</option><option value="tenant" @selected(old('usage_limit') === 'tenant')>One selected tenant</option></select></label>
                    <label class="grid gap-1 text-sm">Maximum total uses<input name="max_redemptions" type="number" min="2" value="{{ old('max_redemptions') }}" class="rounded-xl border p-3"><span class="text-xs text-zinc-500">Required only for “Specified total uses”.</span></label>
                </div>
                <label class="grid gap-1 text-sm">Selected tenant<select name="tenant_id" class="rounded-xl border p-3"><option value="">Choose a tenant</option>@foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected((string) old('tenant_id') === (string) $tenant->id)>{{ $tenant->name }} · {{ $tenant->slug }}</option>@endforeach</select><span class="text-xs text-zinc-500">Required only for “One selected tenant”.</span></label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">Applies to<select name="application_type" class="rounded-xl border p-3"><option value="first_period" @selected(old('application_type') === 'first_period')>First discounted period only</option><option value="limited_periods" @selected(old('application_type') === 'limited_periods')>A limited number of periods</option><option value="recurring" @selected(old('application_type') === 'recurring')>Every renewal while usable</option></select></label>
                    <label class="grid gap-1 text-sm">Periods per subscription<input name="max_uses_per_subscription" type="number" min="1" max="120" value="{{ old('max_uses_per_subscription') }}" class="rounded-xl border p-3"><span class="text-xs text-zinc-500">Required only for limited periods.</span></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">Valid from <span class="text-xs text-zinc-500">Optional</span><input name="starts_at" type="datetime-local" value="{{ old('starts_at') }}" class="rounded-xl border p-3"></label>
                    <label class="grid gap-1 text-sm">Valid until <span class="text-xs text-zinc-500">Optional</span><input name="ends_at" type="datetime-local" value="{{ old('ends_at') }}" class="rounded-xl border p-3"></label>
                </div>
                <button class="w-full rounded-xl bg-emerald-700 px-4 py-3 font-medium text-white">Create voucher</button>
            </form>
        @endif

        <section class="rounded-3xl border bg-white p-6 shadow-sm">
            <div><h2 class="font-semibold">Existing vouchers</h2><p class="mt-1 text-sm text-zinc-500">Usage is counted only when a discounted subscription charge is created.</p></div>
            <div class="mt-4 space-y-3">
                @forelse($vouchers as $voucher)
                    <article class="rounded-2xl border p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3"><div><strong>{{ $voucher->code }}</strong> — {{ $voucher->name }}<p class="mt-1 text-sm text-zinc-600">{{ $voucher->discount_type === 'percent' ? $voucher->discount_value.'%' : number_format($voucher->discount_value).' SYP' }}</p></div><span class="rounded-full px-2.5 py-1 text-xs {{ $voucher->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-zinc-100 text-zinc-600' }}">{{ $voucher->is_active ? 'Active' : 'Inactive' }}</span></div>
                        <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                            <div><dt class="text-zinc-500">Scope</dt><dd>{{ $voucher->tenant ? $voucher->tenant->name : ($voucher->max_redemptions === 1 ? 'One total use' : ($voucher->max_redemptions ? number_format($voucher->max_redemptions).' total uses' : 'All tenants')) }}</dd></div>
                            <div><dt class="text-zinc-500">Application</dt><dd>{{ match($voucher->application_type) { 'recurring' => 'Every renewal', 'limited_periods' => $voucher->max_uses_per_subscription.' periods per subscription', default => 'First discounted period' } }}</dd></div>
                            <div><dt class="text-zinc-500">Recorded uses</dt><dd>{{ number_format($voucher->redemption_records_count) }}{{ $voucher->max_redemptions ? ' / '.number_format($voucher->max_redemptions) : '' }}</dd></div>
                            <div><dt class="text-zinc-500">Validity</dt><dd>{{ $voucher->starts_at?->format('Y-m-d') ?? 'Immediately' }} → {{ $voucher->ends_at?->format('Y-m-d') ?? 'No end date' }}</dd></div>
                        </dl>
                        @if(auth('platform')->user()->hasPlatformPermission('manage.subscriptions'))<form method="POST" action="{{ route('platform.vouchers.status', $voucher) }}" class="mt-4">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $voucher->is_active ? 0 : 1 }}"><button class="text-sm font-medium text-emerald-700">{{ $voucher->is_active ? 'Deactivate voucher' : 'Activate voucher' }}</button></form>@endif
                    </article>
                @empty
                    <p class="rounded-2xl border border-dashed p-6 text-center text-zinc-500">No vouchers have been created.</p>
                @endforelse
            </div>
        </section>
    </section>
</x-platform-layout>
