<x-platform-layout :title="__('platform.ui.storage_overview.title')">
    <div class="platform-management platform-operations">
        <header class="platform-management__header">
            <p class="text-sm font-semibold text-emerald-700">{{ __('platform-workspace.groups.operations') }}</p>
            <h1 class="mt-2 font-bold">{{ __('platform.ui.storage_overview.title') }}</h1>
            <p class="mt-3 max-w-3xl text-zinc-500">{{ __('platform-operations.storage_intro') }}</p>
        </header>
        <x-platform.operations-tabs current="storage" />
        <section>
            <div class="platform-operation-stats">
                <article><span>{{ __('platform-operations.known_total') }}</span><strong dir="ltr">{{ number_format($total / 1048576, 1) }} MB</strong></article>
                @foreach(['scanned', 'overLimit', 'unavailable'] as $key)<article><span>{{ __('platform-operations.'.($key === 'overLimit' ? 'over_limit' : $key)) }}</span><strong>{{ number_format($$key) }}</strong></article>@endforeach
            </div>
            <p class="mt-3 text-sm text-zinc-500">{{ __('platform-operations.scan_help') }}</p>
        </section>
        <section class="platform-request-queue rounded-3xl border bg-white">
            <form method="GET" action="{{ route('platform.storage.index') }}" class="platform-request-filters">
                <label>{{ __('platform-operations.search') }}<input name="search" value="{{ request('search') }}" class="rounded-xl border p-3"></label>
                <label>{{ __('platform-operations.attention') }}<select name="attention" class="rounded-xl border p-3"><option value="">{{ __('platform-operations.all_usage') }}</option><option value="limit" @selected(request('attention') === 'limit')>{{ __('platform-operations.over_limit') }}</option><option value="unavailable" @selected(request('attention') === 'unavailable')>{{ __('platform-operations.unavailable') }}</option></select></label>
                <button class="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white">{{ __('platform-operations.filter') }}</button>
                <a href="{{ route('platform.storage.index') }}">{{ __('platform-operations.reset') }}</a>
            </form>
            <div class="overflow-x-auto"><table class="platform-request-table w-full">
                <thead><tr><th>{{ __('platform.ui.common.tenant') }}</th>@foreach(['media','documents','other_files','database','backups','total'] as $key)<th>{{ __($key === 'other_files' ? 'platform-operations.other_files' : 'platform.ui.common.'.$key) }}</th>@endforeach<th>{{ __('platform-operations.limit') }}</th></tr></thead>
                <tbody>@forelse($rows as $row)
                    <tr data-storage-tenant="{{ $row['tenant']->id }}">
                        <td><a href="{{ route('platform.tenants.storage', $row['tenant']) }}" class="font-semibold">{{ $row['tenant']->name }}</a><p dir="ltr">{{ $row['tenant']->slug }}</p></td>
                        @foreach(['media','documents','other_files','database','backups','total'] as $key)<td dir="ltr">{{ $row['usage'] !== null ? number_format($row['usage'][$key] / 1048576, 1).' MB' : __('platform-operations.unknown') }}</td>@endforeach
                        <td>
                            @if($row['tenant']->storage_limit_bytes === null){{ __('platform-operations.unlimited') }}
                            @else
                                <span dir="ltr">{{ number_format($row['tenant']->storage_limit_bytes / 1048576, 1) }} MB</span>
                                @if($row['usage'] !== null && $row['tenant']->storage_limit_bytes > 0)<p>{{ __('platform-operations.usage_percent', ['percent' => number_format($row['usage']['total'] / $row['tenant']->storage_limit_bytes * 100, 1)]) }}</p>
                                @elseif($row['tenant']->storage_limit_bytes === 0)<p>{{ __('platform-operations.zero_limit') }}</p>@endif
                            @endif
                        </td>
                    </tr>
                @empty<tr><td colspan="8" class="text-center">{{ __('platform-operations.empty') }}</td></tr>@endforelse</tbody>
            </table></div>
            <div class="p-5">{{ $rows->links() }}</div>
        </section>
    </div>
</x-platform-layout>
