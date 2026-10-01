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
            <div class="mt-3 space-y-2">
                @forelse ($requests as $item)
                    <div class="rounded border p-3">
                        <strong>{{ $item->subject }}</strong>
                        <span class="ms-2 text-sm text-zinc-500">{{ str($item->type)->title() }} - {{ \App\Models\TenantSupportRequest::statusLabel($item->status) }}</span>
                        @if ($item->type === \App\Models\TenantSupportRequest::TYPE_PROBLEM && $item->priority)
                            <span class="ms-2 text-sm text-zinc-500">Priority: {{ str($item->priority)->title() }}</span>
                        @endif
                        <p class="mt-1 text-sm">{{ $item->message }}</p>
                    </div>
                @empty
                    <p class="text-zinc-500">No requests yet.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.app>