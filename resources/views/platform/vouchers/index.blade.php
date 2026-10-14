<x-platform-layout :title="__('platform-billing.vouchers')"><div class="platform-management platform-vouchers">
    <header class="platform-management__header"><h1 class="font-bold">{{ __('platform-billing.vouchers') }}</h1><p class="mt-3 text-zinc-500">{{ __('platform-billing.voucher_intro') }}</p></header><x-platform.billing-tabs current="vouchers" />
    @if(auth('platform')->user()->hasPlatformPermission('manage.subscriptions'))<details class="rounded-3xl border bg-white p-6" @if($errors->any()) open @endif><summary class="cursor-pointer text-lg font-bold">{{ __('platform-billing.create') }}</summary>@include('platform.vouchers.form')</details>@endif
    <section class="platform-request-queue rounded-3xl border bg-white"><form method="GET" action="{{ route('platform.vouchers.index') }}" class="platform-request-filters"><label>{{ __('platform-requests.search') }}<input name="search" value="{{ request('search') }}" class="rounded-xl border p-3"></label><label>{{ __('platform.ui.common.status') }}<select name="status" class="rounded-xl border p-3"><option value="">{{ __('platform-requests.all') }}</option>@foreach(['active','inactive'] as $value)<option value="{{ $value }}" @selected(request('status') === $value)>{{ __('platform-billing.'.$value) }}</option>@endforeach</select></label><button class="rounded-xl border px-4 py-3">{{ __('platform.ui.dashboard.filter') }}</button><a href="{{ route('platform.vouchers.index') }}">{{ __('platform-design.reset') }}</a></form><p class="p-5 text-sm text-zinc-500">{{ __('platform-billing.usage_help') }}</p>
        <div class="platform-voucher-grid">@forelse($vouchers as $voucher)
            @php($state = !$voucher->is_active ? 'inactive' : ($voucher->ends_at?->isPast() ? 'expired' : ($voucher->starts_at?->isFuture() ? 'scheduled' : ($voucher->max_redemptions !== null && $voucher->redemptions >= $voucher->max_redemptions ? 'exhausted' : 'active'))))
            <article class="rounded-2xl border p-5"><div class="flex flex-wrap justify-between gap-3"><div><h2 class="font-bold" dir="ltr">{{ $voucher->code }}</h2><p class="mt-1 text-sm text-zinc-500">{{ $voucher->name }}</p></div><span class="platform-request-status">{{ __('platform-billing.'.$state) }}</span></div><strong class="platform-voucher-value" dir="ltr">{{ $voucher->discount_type === 'percent' ? $voucher->discount_value.'%' : number_format($voucher->discount_value).' SYP' }}</strong><dl class="platform-request-context"><div><dt>{{ __('platform-billing.scope') }}</dt><dd>{{ $voucher->tenant?->name ?? ($voucher->max_redemptions ? __('platform-billing.count_uses',['count'=>$voucher->max_redemptions]) : __('platform-billing.all_tenants')) }}</dd></div><div><dt>{{ __('platform-billing.application') }}</dt><dd>{{ $voucher->application_type === 'limited_periods' ? __('platform-billing.count_periods',['count'=>$voucher->max_uses_per_subscription]) : __('platform-billing.'.$voucher->application_type) }}</dd></div><div><dt>{{ __('platform-billing.uses') }}</dt><dd>{{ $voucher->redemption_records_count }}{{ $voucher->max_redemptions ? ' / '.$voucher->max_redemptions : '' }}</dd></div><div><dt>{{ __('platform-billing.validity') }}</dt><dd>{{ $voucher->starts_at?->format('Y-m-d H:i') ?? __('platform-billing.immediately') }} → {{ $voucher->ends_at?->format('Y-m-d H:i') ?? __('platform-billing.no_end') }}</dd></div></dl>
                @if(auth('platform')->user()->hasPlatformPermission('manage.subscriptions'))<form method="POST" action="{{ route('platform.vouchers.status',$voucher) }}" class="mt-5">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $voucher->is_active ? 0 : 1 }}"><button class="rounded-xl border px-4 py-2 text-sm font-semibold">{{ __('platform-billing.'.($voucher->is_active ? 'deactivate' : 'activate')) }}</button></form>@endif
            </article>@empty<p class="p-5 text-zinc-500">{{ __('platform-requests.empty') }}</p>@endforelse
        </div>@if($vouchers->hasPages())<div class="border-t p-5">{{ $vouchers->links() }}</div>@endif
    </section>
</div>
@if(auth('platform')->user()->hasPlatformPermission('manage.subscriptions'))
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-voucher-form]'); if(!form) return;
    const update = () => {
        const usage = form.elements.usage_limit.value, application = form.elements.application_type.value;
        [['[data-voucher-total]',usage === 'total'],['[data-voucher-tenant]',usage === 'tenant'],['[data-voucher-periods]',application === 'limited_periods']].forEach(([selector, needed]) => { const control = form.querySelector(selector); control.closest('label').hidden = !needed; control.disabled = !needed; control.required = needed; });
        form.elements.discount_value.max = form.elements.discount_type.value === 'percent' ? 100 : 999999999999;
    };
    form.addEventListener('change',update); update();
});
</script>
@endif
</x-platform-layout>
