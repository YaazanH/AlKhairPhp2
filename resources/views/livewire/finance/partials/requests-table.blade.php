<section class="surface-table">
    <div class="admin-grid-meta">
        <div>
            <div class="admin-grid-meta__title">Request ledger</div>
            <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($requests->total())]) }}</div>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="text-sm">
            <thead>
                <tr>
                    <th class="px-5 py-3 text-left">Request</th>
                    <th class="px-5 py-3 text-left">Context</th>
                    <th class="px-5 py-3 text-left">Category</th>
                    <th class="px-5 py-3 text-left">Amounts</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/6">
                @forelse ($requests as $request)
                    <tr>
                        <td class="px-5 py-3">
                            <div class="font-medium text-white">{{ $request->request_no }}</div>
                            <div class="text-xs text-neutral-500">{{ $request->created_at?->format('Y-m-d H:i') }} | {{ $request->requestedBy?->name ?: '-' }}</div>
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
                        <td class="px-5 py-3">{{ $request->category?->name ?: '-' }}</td>
                        <td class="px-5 py-3">
                            <div>Requested: {{ number_format((float) $request->requested_amount, 2) }} {{ $request->requestedCurrency?->code }}</div>
                            <div class="text-xs text-neutral-500">Accepted: {{ $request->accepted_amount !== null ? number_format((float) $request->accepted_amount, 2).' '.$request->acceptedCurrency?->code : '-' }}</div>
                        </td>
                        <td class="px-5 py-3"><span class="status-chip {{ $request->status === 'accepted' ? 'status-chip--emerald' : ($request->status === 'declined' ? 'status-chip--rose' : 'status-chip--slate') }}">{{ ucfirst($request->status) }}</span></td>
                        <td class="px-5 py-3">
                            <div class="admin-action-cluster admin-action-cluster--end">
                                @if ($request->status === 'accepted')
                                    <a href="{{ route('finance.requests.print', $request) }}" target="_blank" class="pill-link pill-link--compact">Print</a>
                                @endif
                                @can($reviewPermission)
                                    @if ($request->status === 'pending')
                                        <input wire:model="review_amounts.{{ $request->id }}" type="number" min="0" step="0.01" placeholder="{{ number_format((float) $request->requested_amount, 2, '.', '') }}" class="w-28 rounded-xl px-3 py-2 text-sm">
                                        <select wire:model="review_cash_boxes.{{ $request->id }}" class="w-36 rounded-xl px-3 py-2 text-sm">
                                            <option value="">Box</option>
                                            @foreach ($cashBoxes as $box)
                                                <option value="{{ $box->id }}">{{ $box->name }}</option>
                                            @endforeach
                                        </select>
                                        <input wire:model="review_notes.{{ $request->id }}" type="text" placeholder="Notes" class="w-40 rounded-xl px-3 py-2 text-sm">
                                        <button type="button" wire:click="accept({{ $request->id }})" class="pill-link pill-link--compact pill-link--accent">Accept</button>
                                        <button type="button" wire:click="decline({{ $request->id }})" class="pill-link pill-link--compact border-red-400/25 text-red-200">Decline</button>
                                    @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-neutral-500">No requests yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($requests->hasPages()) <div class="border-t border-white/8 px-5 py-4">{{ $requests->links() }}</div> @endif
</section>
