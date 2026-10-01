<x-layouts.app>
    <div class="mx-auto max-w-5xl space-y-6 p-6">
        <header>
            <h1 class="text-2xl font-bold">Tenant support requests</h1>
            <p class="text-zinc-500">Review internal reports and forward requests that need Platform attention.</p>
            <p class="mt-2 inline-block rounded-full bg-amber-100 px-3 py-1 text-sm text-amber-900">{{ $openCount }} need attention</p>
        </header>

        <div class="space-y-4">
            @forelse ($requests as $item)
                @php($platformCase = $platformCases->get($item->id))
                <section class="rounded-2xl border p-5">
                    <div class="flex justify-between gap-4">
                        <strong>{{ $item->subject }}</strong>
                        <span>{{ str($item->type)->title() }}</span>
                    </div>
                    <p class="mt-2">{{ $item->message }}</p>
                    <p class="mt-2 text-sm text-zinc-500">Submitted by {{ $item->submittedBy?->name }}</p>
                    @if ($item->type === \App\Models\TenantSupportRequest::TYPE_PROBLEM && $item->priority)
                        <p class="mt-1 text-sm text-zinc-500">Priority: {{ str($item->priority)->title() }}</p>
                    @endif
                    @if ($platformCase)
                        <div class="mt-3 rounded border border-sky-200 bg-sky-50 p-3 text-sm">
                            <strong>Platform case: {{ \App\Models\Landlord\PlatformSupportCase::statusLabel($platformCase->status) }}</strong>
                            @if ($platformCase->platform_note)
                                <p class="mt-1"><strong>Reply for tenant administrator:</strong> {{ $platformCase->platform_note }}</p>
                            @endif
                        </div>
                    @endif

                    <div class="mt-4 space-y-2 border-t pt-3">
                        @forelse ($item->messages as $supportMessage)
                            <div class="rounded bg-zinc-50 p-3 text-sm">
                                <strong>{{ $supportMessage->is_tenant_administrator ? 'Tenant administrator' : $supportMessage->sender?->name }}</strong>
                                <span class="text-zinc-500">{{ $supportMessage->created_at->format('Y-m-d H:i') }}</span>
                                <p class="mt-1">{{ $supportMessage->message }}</p>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500">No conversation yet.</p>
                        @endforelse
                    </div>

                    @if ($item->type === \App\Models\TenantSupportRequest::TYPE_PROBLEM)
                        <div class="mt-3 space-y-1 text-sm">
                            @forelse ($item->attachments as $attachment)
                                <a href="{{ route('support.attachments.download', $attachment) }}" class="block text-emerald-700 underline">Attachment: {{ $attachment->original_name }}</a>
                            @empty
                                <p class="text-zinc-500">No attachments.</p>
                            @endforelse
                        </div>
                    @endif

                    <form method="POST" action="{{ route('support.messages.store', $item) }}" class="mt-3 flex gap-2">
                        @csrf
                        <input name="message" required maxlength="5000" placeholder="Reply to the submitter" class="min-w-0 flex-1 rounded border p-2">
                        <button class="rounded border px-3">Reply</button>
                    </form>
                    @if ($item->type === \App\Models\TenantSupportRequest::TYPE_PROBLEM)
                        <form method="POST" action="{{ route('support.attachments.store', $item) }}" enctype="multipart/form-data" class="mt-2 flex gap-2">
                            @csrf
                            <input type="file" name="attachment" required accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" class="min-w-0 flex-1 rounded border p-2 text-sm">
                            <button class="rounded border px-3">Attach file</button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('support.update', $item) }}" class="mt-4 grid gap-3 border-t pt-4">
                        @csrf
                        @method('PUT')
                        <select name="status" class="rounded border p-3">
                            @foreach ($statusOptions[$item->type] as $status)
                                <option value="{{ $status }}" @selected($item->status === $status)>{{ \App\Models\TenantSupportRequest::statusLabel($status) }}</option>
                            @endforeach
                        </select>
                        <textarea name="tenant_admin_note" placeholder="Internal note, not visible to the submitter" class="rounded border p-3">{{ $item->tenant_admin_note }}</textarea>
                        <label>
                            <input type="checkbox" name="forward" value="1">
                            {{ $platformCase ? 'Send the latest request details to Platform' : 'Forward to Platform' }}
                        </label>
                        <button class="rounded bg-emerald-700 px-4 py-2 text-white">Save request</button>
                    </form>
                </section>
            @empty
                <p>No requests yet.</p>
            @endforelse
        </div>
    </div>
</x-layouts.app>