<x-layouts.app :title="__('support.manage.title')">
    <div class="page-stack support-page" data-support-page="manage">
        <section class="page-hero p-6 lg:p-8">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <a href="{{ route('support.index') }}" class="app-back-link text-white/70 hover:text-white">
                        <span aria-hidden="true">←</span>
                        {{ __('support.manage.back') }}
                    </a>
                    <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('support.manage.title') }}</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-7 text-white/75">{{ __('support.manage.description') }}</p>
                </div>
                <span class="status-chip {{ $openCount > 0 ? 'status-chip--amber' : 'status-chip--emerald' }}">
                    {{ trans_choice('support.manage.attention_count', $openCount, ['count' => $openCount]) }}
                </span>
            </div>
        </section>

        @if (session('status'))
            <div class="flash-success px-4 py-3 text-sm" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-red-400/30 bg-red-400/10 px-5 py-4 text-sm text-red-100" role="alert">
                <ul class="list-disc space-y-1 ps-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="surface-panel settings-dark-surface p-5 lg:p-6">
            <div class="admin-toolbar">
                <div>
                    <div class="admin-toolbar__title">{{ __('support.manage.queue') }}</div>
                    <p class="admin-toolbar__subtitle">{{ __('support.manage.queue_help') }}</p>
                </div>
                <span class="status-chip status-chip--slate">{{ $requests->count() }}</span>
            </div>

            <div class="mt-6 space-y-5">
                @forelse ($requests as $item)
                    @php
                        $platformCase = $platformCases->get($item->id);
                        $statusTone = match ($item->status) {
                            'resolved', 'released', 'implemented_internally' => 'emerald',
                            'closed', 'declined' => 'slate',
                            'forwarded', 'in_progress', 'planned' => 'blue',
                            default => 'amber',
                        };
                    @endphp
                    <article class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.025]" data-support-request="{{ $item->id }}">
                        <header class="border-b border-white/10 p-4 lg:p-5">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="status-chip status-chip--{{ $statusTone }}">{{ \App\Models\TenantSupportRequest::statusLabel($item->status) }}</span>
                                        <span class="status-chip status-chip--slate">{{ \App\Models\TenantSupportRequest::typeLabel($item->type) }}</span>
                                        @if ($item->incident_reference)
                                            <span class="font-mono text-xs text-neutral-400">{{ $item->incident_reference }}</span>
                                        @endif
                                    </div>
                                    <h2 class="mt-3 text-lg font-semibold text-white">{{ $item->subject }}</h2>
                                </div>
                                <div class="shrink-0 text-start text-xs leading-6 text-neutral-400 sm:text-end">
                                    <p>{{ __('support.manage.submitted_by', ['name' => $item->submittedBy?->name]) }}</p>
                                    <p>{{ __('support.common.submitted_at', ['date' => $item->created_at->format('Y-m-d H:i')]) }}</p>
                                </div>
                            </div>
                        </header>

                        <div class="grid xl:grid-cols-[minmax(0,1fr)_22rem]">
                            <div class="space-y-6 p-4 lg:p-5 xl:border-e xl:border-white/10">
                                <section>
                                    <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-neutral-400">{{ __('support.common.request_details') }}</h3>
                                    <p class="mt-3 whitespace-pre-line text-sm leading-7 text-neutral-200">{{ $item->message }}</p>
                                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                        @if ($item->type === \App\Models\TenantSupportRequest::TYPE_PROBLEM)
                                            @if ($item->problem_reason)<div class="rounded-xl border border-white/10 bg-black/10 p-3 text-sm text-neutral-300">{{ __('support.common.reason', ['reason' => $allProblemReasonOptions[$item->problem_reason] ?? $item->problem_reason]) }}</div>@endif
                                            @if ($item->priority)<div class="rounded-xl border border-white/10 bg-black/10 p-3 text-sm text-neutral-300">{{ __('support.common.priority', ['priority' => $allPriorityOptions[$item->priority] ?? $item->priority]) }}</div>@endif
                                            @if ($item->impact)<div class="rounded-xl border border-white/10 bg-black/10 p-3 text-sm text-neutral-300">{{ __('support.common.impact', ['impact' => $allImpactOptions[$item->impact] ?? $item->impact]) }}</div>@endif
                                            @if ($item->expected_result)<div class="rounded-xl border border-white/10 bg-black/10 p-3 text-sm text-neutral-300 sm:col-span-2"><strong class="text-white">{{ __('support.common.expected') }}</strong> {{ $item->expected_result }}</div>@endif
                                        @else
                                            @if ($item->desired_outcome)<div class="rounded-xl border border-white/10 bg-black/10 p-3 text-sm text-neutral-300 sm:col-span-2"><strong class="text-white">{{ __('support.common.desired_outcome') }}</strong> {{ $item->desired_outcome }}</div>@endif
                                            @if ($item->current_workaround)<div class="rounded-xl border border-white/10 bg-black/10 p-3 text-sm text-neutral-300"><strong class="text-white">{{ __('support.common.current_workaround') }}</strong> {{ $item->current_workaround }}</div>@endif
                                            @if ($item->affected_users)<div class="rounded-xl border border-white/10 bg-black/10 p-3 text-sm text-neutral-300"><strong class="text-white">{{ __('support.common.affected_users') }}</strong> {{ $item->affected_users }}</div>@endif
                                            @if ($item->business_impact)<div class="rounded-xl border border-white/10 bg-black/10 p-3 text-sm text-neutral-300">{{ __('support.common.business_impact', ['impact' => \App\Models\TenantSupportRequest::businessImpactOptions()[$item->business_impact] ?? $item->business_impact]) }}</div>@endif
                                            @if ($item->decline_reason)<div class="rounded-xl border border-amber-300/20 bg-amber-300/5 p-3 text-sm text-neutral-200 sm:col-span-2"><strong class="text-white">{{ __('support.manage.decline_reason') }}</strong> {{ $item->decline_reason }}</div>@endif
                                        @endif
                                    </div>
                                </section>

                                @if ($platformCase)
                                    <section class="rounded-2xl border border-blue-300/20 bg-blue-400/5 p-4 text-sm">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <strong class="text-blue-100">{{ __('support.manage.platform_case', ['status' => \App\Models\Landlord\PlatformSupportCase::statusLabel($platformCase->status)]) }}</strong>
                                            <span class="status-chip status-chip--blue">{{ \App\Models\Landlord\PlatformSupportCase::statusLabel($platformCase->status) }}</span>
                                        </div>
                                        @if ($platformCase->platform_note)
                                            <p class="mt-3 leading-6 text-neutral-300"><strong class="text-white">{{ __('support.manage.platform_reply') }}</strong> {{ $platformCase->platform_note }}</p>
                                        @endif
                                    </section>
                                @endif

                                <section class="border-t border-white/10 pt-5">
                                    <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-neutral-400">{{ __('support.common.conversation') }}</h3>
                                    <div class="mt-3 space-y-3">
                                        @forelse ($item->messages as $supportMessage)
                                            <div class="rounded-2xl border border-white/10 bg-black/10 p-4 text-sm">
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <strong class="text-white">{{ $supportMessage->is_tenant_administrator ? __('support.common.tenant_administrator') : $supportMessage->sender?->name }}</strong>
                                                    <span class="text-xs text-neutral-500">{{ $supportMessage->created_at->format('Y-m-d H:i') }}</span>
                                                </div>
                                                <p class="mt-2 whitespace-pre-line leading-6 text-neutral-300">{{ $supportMessage->message }}</p>
                                            </div>
                                        @empty
                                            <p class="rounded-xl border border-dashed border-white/10 p-4 text-sm text-neutral-500">{{ __('support.manage.no_conversation') }}</p>
                                        @endforelse
                                    </div>

                                    <form method="POST" action="{{ route('support.messages.store', $item) }}" class="mt-4 flex flex-col gap-3 sm:flex-row">
                                        @csrf
                                        <textarea name="message" required maxlength="5000" rows="2" placeholder="{{ __('support.manage.reply_placeholder') }}" class="min-w-0 flex-1 rounded-xl border border-white/10 bg-black/20 px-4 py-3 text-sm text-white placeholder:text-neutral-500 focus:border-emerald-400/60 focus:outline-none"></textarea>
                                        <button class="pill-link pill-link--accent self-stretch justify-center sm:self-end">{{ __('support.common.reply') }}</button>
                                    </form>
                                </section>

                                @if ($item->type === \App\Models\TenantSupportRequest::TYPE_PROBLEM)
                                    <section class="border-t border-white/10 pt-5">
                                        <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-neutral-400">{{ __('support.common.attachments') }}</h3>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @forelse ($item->attachments as $attachment)
                                                <a href="{{ route('support.attachments.download', $attachment) }}" class="pill-link pill-link--compact max-w-full">
                                                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m21.4 11.6-8.9 8.9a6 6 0 0 1-8.5-8.5l9.6-9.6a4 4 0 0 1 5.7 5.7l-9.6 9.6a2 2 0 0 1-2.8-2.8l8.9-8.9" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                                    <span class="truncate">{{ $attachment->original_name }}</span>
                                                </a>
                                            @empty
                                                <p class="text-sm text-neutral-500">{{ __('support.common.no_attachments') }}</p>
                                            @endforelse
                                        </div>
                                        <form method="POST" action="{{ route('support.attachments.store', $item) }}" enctype="multipart/form-data" class="mt-4 flex flex-col gap-3 rounded-xl border border-white/10 bg-black/10 p-3 sm:flex-row sm:items-center">
                                            @csrf
                                            <input type="file" name="attachment" required accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" class="min-w-0 flex-1 text-xs text-neutral-400 file:me-3 file:rounded-full file:border-0 file:bg-emerald-400/10 file:px-3 file:py-2 file:text-emerald-200">
                                            <button class="pill-link pill-link--compact justify-center">{{ __('support.common.attach_file') }}</button>
                                        </form>
                                    </section>
                                @endif
                            </div>

                            <aside class="bg-black/10 p-4 lg:p-5">
                                <div class="xl:sticky xl:top-6">
                                    <h3 class="text-base font-semibold text-white">{{ __('support.manage.request_actions') }}</h3>
                                    <p class="mt-1 text-xs leading-5 text-neutral-500">{{ __('support.manage.request_actions_help') }}</p>
                                    <form method="POST" action="{{ route('support.update', $item) }}" class="mt-5 space-y-4">
                                        @csrf
                                        @method('PUT')
                                        <label class="block">
                                            <span class="mb-2 block text-xs font-medium text-neutral-300">{{ __('support.common.status') }}</span>
                                            <select name="status" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-3 text-sm text-white focus:border-emerald-400/60 focus:outline-none">
                                                @foreach ($statusOptions[$item->type] as $status)
                                                    <option value="{{ $status }}" @selected($item->status === $status)>{{ \App\Models\TenantSupportRequest::statusLabel($status) }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        @if ($item->type === \App\Models\TenantSupportRequest::TYPE_PROBLEM)
                                            <label class="block">
                                                <span class="mb-2 block text-xs font-medium text-neutral-300">{{ __('support.index.reason') }}</span>
                                                <select name="problem_reason" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-3 text-sm text-white focus:border-emerald-400/60 focus:outline-none">
                                                    @foreach ($problemReasonOptions as $reason => $label)
                                                        <option value="{{ $reason }}" @selected($item->problem_reason === $reason)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label class="block">
                                                <span class="mb-2 block text-xs font-medium text-neutral-300">{{ __('support.index.problem_priority', ['priority' => '']) }}</span>
                                                <select name="priority" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-3 text-sm text-white focus:border-emerald-400/60 focus:outline-none">
                                                    @foreach ($priorityOptions as $priority => $label)
                                                        <option value="{{ $priority }}" @selected($item->priority === $priority)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        @else
                                            <label class="block">
                                                <span class="sr-only">{{ __('support.manage.decline_placeholder') }}</span>
                                                <textarea name="decline_reason" rows="3" placeholder="{{ __('support.manage.decline_placeholder') }}" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-3 text-sm text-white placeholder:text-neutral-500 focus:border-emerald-400/60 focus:outline-none">{{ $item->decline_reason }}</textarea>
                                            </label>
                                        @endif
                                        <label class="block">
                                            <span class="sr-only">{{ __('support.manage.internal_note') }}</span>
                                            <textarea name="tenant_admin_note" rows="3" placeholder="{{ __('support.manage.internal_note') }}" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-3 text-sm text-white placeholder:text-neutral-500 focus:border-emerald-400/60 focus:outline-none">{{ $item->tenant_admin_note }}</textarea>
                                        </label>
                                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-white/[0.025] p-3">
                                            <input type="checkbox" name="forward" value="1" class="mt-1 rounded border-white/20 bg-black/20 text-emerald-600 focus:ring-emerald-500">
                                            <span>
                                                <span class="block text-sm font-medium text-white">{{ $platformCase ? __('support.manage.forward_latest') : __('support.manage.forward') }}</span>
                                                <span class="mt-1 block text-xs leading-5 text-neutral-500">{{ __('support.manage.forward_help') }}</span>
                                            </span>
                                        </label>
                                        <button class="pill-link pill-link--accent w-full justify-center">{{ __('support.manage.save') }}</button>
                                    </form>
                                </div>
                            </aside>
                        </div>
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-white/10 px-5 py-12 text-center text-neutral-500">{{ __('support.common.no_requests') }}</div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.app>
