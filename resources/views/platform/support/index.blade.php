<x-platform-layout title="Support cases">
    <header>
        <h1 class="text-3xl font-bold">Forwarded tenant requests</h1>
        <p class="mt-2 text-zinc-600">Problems and suggestions forwarded by tenant administrators.</p>
        <p class="mt-2 inline-block rounded-full bg-amber-100 px-3 py-1 text-sm text-amber-900">{{ $attentionCount }} need attention</p>
    </header>

    @if ($canManageSuggestions)
        <section class="rounded-3xl border bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">Shared suggestion groups</h2>
            <p class="mt-1 text-sm text-zinc-600">Group duplicate tenant suggestions under one shared product item without removing their original cases.</p>
            <form method="POST" action="{{ route('platform.support.suggestion-groups.store') }}" class="mt-4 grid gap-2">
                @csrf
                <input name="title" required maxlength="180" placeholder="Shared suggestion title" class="rounded border p-2">
                <textarea name="summary" rows="2" placeholder="Optional summary for Platform staff" class="rounded border p-2"></textarea>
                <button class="w-fit rounded border px-3 py-2">Create group</button>
            </form>
        </section>
    @endif

    <section class="rounded-3xl border bg-white p-6 shadow-sm">
        <div class="space-y-3">
            @forelse ($cases as $case)
                <article class="rounded-2xl border p-4">
                    <div class="flex justify-between gap-4">
                        <strong>{{ $case->subject }}</strong>
                        <span>{{ str($case->type)->title() }}</span>
                        @if ($case->incident_reference)<span class="text-sm text-zinc-500">{{ $case->incident_reference }}</span>@endif
                    </div>
                    <p class="mt-2">{{ $case->message }}</p>
                    @if ($case->type === 'suggestion' && $case->suggestionGroup)
                        <p class="mt-2 text-sm text-violet-700">Shared group: {{ $case->suggestionGroup->title }}</p>
                    @endif
                    @if ($case->type === 'problem')
                        <a href="{{ route('platform.support.attachments.index', $case) }}" class="mt-3 inline-block text-sm text-emerald-700 underline">View tenant attachments</a>
                    @endif
                    <p class="mt-2 text-sm text-zinc-500">{{ $case->tenant->name }} - {{ $case->forwarded_at->format('Y-m-d H:i') }}</p>
                    <form method="POST" action="{{ route('platform.support.update', $case) }}" class="mt-3 grid gap-2">
                        @csrf
                        @method('PUT')
                        <select name="status" class="rounded border p-2">
                            @foreach ($statusOptions[$case->type] as $status)
                                <option value="{{ $status }}" @selected($case->status === $status)>{{ \App\Models\Landlord\PlatformSupportCase::statusLabel($status) }}</option>
                            @endforeach
                        </select>
                        @if ($case->type === 'suggestion')
                            <select name="platform_suggestion_group_id" class="rounded border p-2">
                                <option value="">No shared suggestion group</option>
                                @foreach ($suggestionGroups as $group)
                                    <option value="{{ $group->id }}" @selected($case->platform_suggestion_group_id === $group->id)>{{ $group->title }}</option>
                                @endforeach
                            </select>
                        @endif
                        <textarea name="platform_note" placeholder="Reply for the tenant administrator" class="rounded border p-2">{{ $case->platform_note }}</textarea>
                        <button class="w-fit rounded border px-3 py-2">Save status</button>
                    </form>
                </article>
            @empty
                <p class="text-zinc-500">No forwarded requests.</p>
            @endforelse
        </div>
    </section>
</x-platform-layout>