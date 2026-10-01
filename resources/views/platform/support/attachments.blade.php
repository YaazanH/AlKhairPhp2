<x-platform-layout title="Problem attachments">
    <header>
        <a href="{{ route('platform.support.index') }}" class="text-sm text-emerald-700 underline">Back to support cases</a>
        <h1 class="mt-3 text-3xl font-bold">{{ $case->subject }}</h1>
        <p class="mt-2 text-zinc-600">Tenant problem attachments are available only because this case was forwarded to Platform.</p>
    </header>

    <section class="mt-6 rounded-3xl border bg-white p-6 shadow-sm">
        <div class="space-y-3">
            @forelse ($attachments as $attachment)
                <a href="{{ route('platform.support.attachments.download', [$case, $attachment->id]) }}" class="flex items-center justify-between rounded-2xl border p-4 hover:bg-zinc-50">
                    <span>{{ $attachment->original_name }}</span>
                    <span class="text-sm text-zinc-500">{{ number_format($attachment->size_bytes / 1024, 1) }} KB</span>
                </a>
            @empty
                <p class="text-zinc-500">No attachments were added to this problem report.</p>
            @endforelse
        </div>
    </section>
</x-platform-layout>