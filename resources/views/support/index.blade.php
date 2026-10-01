<x-layouts.app>
    <div class="mx-auto max-w-4xl space-y-6 p-6">
        <header>
            <h1 class="text-2xl font-bold">Help and improvements</h1>
            <p class="text-zinc-500">Report a problem or suggest an improvement for your organisation.</p>
            @if ($canManage)
                <a href="{{ route('support.manage') }}" class="mt-3 inline-block text-sm text-emerald-700 underline">Manage tenant requests</a>
            @endif
        </header>

        @if ($canSubmitProblem || $canSubmitSuggestion)
            <form method="POST" action="{{ route('support.store') }}" class="space-y-3 rounded-2xl border p-5" x-data="{ type: '{{ $canSubmitProblem ? 'problem' : 'suggestion' }}' }">
                @csrf
                <select name="type" x-model="type" class="w-full rounded border p-3">
                    @if ($canSubmitProblem)<option value="problem">Report a problem</option>@endif
                    @if ($canSubmitSuggestion)<option value="suggestion">Suggest an improvement</option>@endif
                </select>
                <select name="priority" x-show="type === 'problem'" x-cloak class="w-full rounded border p-3">
                    <option value="normal">Problem priority: Normal</option>
                    <option value="high">Problem priority: High</option>
                    <option value="critical">Problem priority: Critical</option>
                </select>
                <input name="reported_url" type="hidden" value="{{ url()->current() }}">
                <input name="subject" required placeholder="Short subject" class="w-full rounded border p-3">
                <textarea name="message" required rows="5" placeholder="Describe what happened or what would help" class="w-full rounded border p-3"></textarea>
                <button class="rounded bg-emerald-700 px-4 py-3 text-white">Submit</button>
            </form>
        @endif

        <section class="rounded-2xl border p-5">
            <h2 class="font-semibold">My requests</h2>
            <div class="mt-3 space-y-4">
                @forelse ($requests as $item)
                    <article class="rounded border p-4">
                        <strong>{{ $item->subject }}</strong>
                        <span class="ms-2 text-sm text-zinc-500">{{ str($item->type)->title() }} - {{ \App\Models\TenantSupportRequest::statusLabel($item->status) }}</span>
                        @if ($item->type === \App\Models\TenantSupportRequest::TYPE_PROBLEM && $item->priority)
                            <span class="ms-2 text-sm text-zinc-500">Priority: {{ str($item->priority)->title() }}</span>
                        @endif
                        <p class="mt-1 text-sm">{{ $item->message }}</p>

                        <div class="mt-4 space-y-2 border-t pt-3">
                            @forelse ($item->messages as $supportMessage)
                                <div class="rounded bg-zinc-50 p-3 text-sm">
                                    <strong>{{ $supportMessage->is_tenant_administrator ? 'Tenant administrator' : $supportMessage->sender?->name }}</strong>
                                    <span class="text-zinc-500">{{ $supportMessage->created_at->format('Y-m-d H:i') }}</span>
                                    <p class="mt-1">{{ $supportMessage->message }}</p>
                                </div>
                            @empty
                                <p class="text-sm text-zinc-500">No replies yet.</p>
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

                        @if (! $item->isClosed())
                            <form method="POST" action="{{ route('support.messages.store', $item) }}" class="mt-3 flex gap-2">
                                @csrf
                                <input name="message" required maxlength="5000" placeholder="Add information or reply" class="min-w-0 flex-1 rounded border p-2">
                                <button class="rounded border px-3">Send</button>
                            </form>
                            @if ($item->type === \App\Models\TenantSupportRequest::TYPE_PROBLEM)
                                <form method="POST" action="{{ route('support.attachments.store', $item) }}" enctype="multipart/form-data" class="mt-2 flex gap-2">
                                    @csrf
                                    <input type="file" name="attachment" required accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" class="min-w-0 flex-1 rounded border p-2 text-sm">
                                    <button class="rounded border px-3">Attach file</button>
                                </form>
                                <p class="mt-1 text-xs text-zinc-500">Images and common documents up to 10 MB.</p>
                            @endif
                        @endif
                    </article>
                @empty
                    <p class="text-zinc-500">No requests yet.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.app>