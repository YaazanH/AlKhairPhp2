<section class="surface-table">
    <div class="admin-grid-meta">
        <div>
            <div class="admin-grid-meta__title">{{ __('finance.common.request') }}</div>
            <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($requests->total())]) }}</div>
        </div>
        @if (($createPermission ?? null) && ($createMethod ?? null) && ($createLabel ?? null))
            @can($createPermission)
                <button type="button" wire:click="{{ $createMethod }}" class="pill-link pill-link--accent">{{ $createLabel }}</button>
            @endcan
        @endif
    </div>
    <div class="overflow-x-auto">
        <table class="text-sm">
            <thead>
                <tr>
                    <th class="px-5 py-3 text-left">{{ __('finance.common.request') }}</th>
                    <th class="px-5 py-3 text-left">{{ __('finance.fields.activity') }}</th>
                    <th class="px-5 py-3 text-left">{{ __('finance.fields.category') }}</th>
                    <th class="px-5 py-3 text-left">{{ __('finance.common.amounts') }}</th>
                    <th class="px-5 py-3 text-left">{{ __('finance.common.status') }}</th>
                    <th class="px-5 py-3 text-right">{{ __('finance.actions.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/6">
                @forelse ($requests as $request)
                    @php
                        $printPermission = match ($request->type) {
                            \App\Models\FinanceRequest::TYPE_PULL => 'finance.pull-requests.print',
                            \App\Models\FinanceRequest::TYPE_EXPENSE => 'finance.expense-requests.print',
                            default => 'finance.revenue-requests.print',
                        };
                    @endphp
                    <tr>
                        <td class="px-5 py-3">
                            <div class="font-medium text-white">{{ $request->request_no }}</div>
                            <div class="text-xs text-neutral-500">{{ $request->created_at?->format('Y-m-d H:i') }} | {{ $request->requestedBy?->name ?: '-' }}</div>
                            @if ($request->requested_reason)
                                <div class="mt-1 max-w-xs text-xs leading-5 text-neutral-400">{{ $request->requested_reason }}</div>
                            @endif
                            @if ($request->attachments->isNotEmpty())
                                <div class="mt-1 flex flex-wrap gap-2 text-xs">
                                    @foreach ($request->attachments as $attachment)
                                        <a href="{{ asset('storage/'.$attachment->path) }}" target="_blank" class="text-emerald-200 underline decoration-emerald-300/40 underline-offset-4">{{ $attachment->original_name ?: 'Attachment' }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            <div>{{ $request->activity?->title ?: '-' }}</div>
                            <div class="text-xs text-neutral-500">{{ $request->teacher ? trim($request->teacher->first_name.' '.$request->teacher->last_name) : '-' }}</div>
                        </td>
                        <td class="px-5 py-3">
                            <div>{{ $request->category?->name ?: ($request->pullRequestKind ? __('finance.fields.pull_kind') : ($request->type === \App\Models\FinanceRequest::TYPE_PULL ? __('finance.pull_requests.title') : '-')) }}</div>
                            @if ($request->pullRequestKind)
                                <div class="text-xs text-neutral-500">{{ $request->pullRequestKind->name }} - {{ __('finance.pull_modes.'.$request->pullRequestKind->mode) }}</div>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            @if ($request->accepted_amount !== null)
                                <div class="text-base font-semibold text-white">{{ __('finance.fields.accepted') }}: {{ number_format((float) $request->accepted_amount, 2) }} {{ $request->acceptedCurrency?->code }}</div>
                                <div class="mt-1 text-xs text-neutral-500">{{ __('finance.fields.requested') }}: {{ number_format((float) $request->requested_amount, 2) }} {{ $request->requestedCurrency?->code }}</div>
                            @else
                                <div class="text-base font-semibold text-white">{{ __('finance.fields.requested') }}: {{ number_format((float) $request->requested_amount, 2) }} {{ $request->requestedCurrency?->code }}</div>
                                <div class="mt-1 text-xs text-neutral-500">{{ __('finance.fields.accepted') }}: -</div>
                            @endif
                        </td>
                        <td class="px-5 py-3"><span class="status-chip {{ $request->status === 'accepted' ? 'status-chip--emerald' : ($request->status === 'declined' ? 'status-chip--rose' : 'status-chip--slate') }}">{{ __('finance.statuses.'.$request->status) }}</span></td>
                        <td class="px-5 py-3">
                            <div class="admin-action-cluster admin-action-cluster--end">
                                @if ($request->status === 'accepted' && auth()->user()?->can($printPermission))
                                    <a href="{{ route('finance.requests.print', $request) }}" target="_blank" class="pill-link pill-link--compact">{{ __('finance.actions.print') }}</a>
                                    <a href="{{ route('finance.requests.print', ['financeRequest' => $request, 'choose' => 1]) }}" target="_blank" class="pill-link pill-link--compact">{{ __('finance.actions.choose_print_template') }}</a>
                                @endif
                                @can($reviewPermission)
                                    @if ($request->status === 'pending')
                                        <input wire:model="review_amounts.{{ $request->id }}" type="text" inputmode="decimal" data-thousand-separator placeholder="{{ number_format((float) $request->requested_amount, 2) }}" class="w-28 rounded-xl px-3 py-2 text-sm">
                                        <select wire:model="review_cash_boxes.{{ $request->id }}" class="w-36 rounded-xl px-3 py-2 text-sm">
                                            <option value="">{{ __('finance.fields.cash_box') }}</option>
                                            @foreach (($cashBoxesByCurrency[$request->requested_currency_id] ?? $cashBoxes) as $box)
                                                <option value="{{ $box->id }}">{{ $box->name }}</option>
                                            @endforeach
                                        </select>
                                        @can('finance.entries.update')
                                            <input wire:model="review_dates.{{ $request->id }}" type="date" class="w-36 rounded-xl px-3 py-2 text-sm">
                                        @endcan
                                        <input wire:model="review_notes.{{ $request->id }}" type="text" placeholder="{{ __('finance.common.notes') }}" class="w-40 rounded-xl px-3 py-2 text-sm">
                                        <button type="button" wire:click="accept({{ $request->id }})" class="pill-link pill-link--compact pill-link--accent">{{ __('finance.actions.accept') }}</button>
                                        <button type="button" wire:click="decline({{ $request->id }})" class="pill-link pill-link--compact border-red-400/25 text-red-200">{{ __('finance.actions.decline') }}</button>
                                    @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('finance.empty.no_requests') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($requests->hasPages()) <div class="border-t border-white/8 px-5 py-4">{{ $requests->links() }}</div> @endif
</section>
