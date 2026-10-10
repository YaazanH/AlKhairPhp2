<x-layouts.app :title="__('support.index.title')">
    <div class="page-stack support-page" data-support-page="requests">
        <section class="page-hero p-6 lg:p-8">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="eyebrow text-white/60">{{ __('ui.nav.support') }}</p>
                    <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('support.index.title') }}</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-7 text-white/75">{{ __('support.index.description') }}</p>
                </div>
                @if ($canManage)
                    <a href="{{ route('support.manage') }}" class="pill-link pill-link--accent shrink-0">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 5.5h16M4 12h16M4 18.5h10" stroke-linecap="round" /></svg>
                        {{ __('support.index.manage') }}
                    </a>
                @endif
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

        @if ($canSubmitProblem || $canSubmitSuggestion)
            <section class="surface-panel settings-dark-surface p-5 lg:p-6" data-support-composer>
                <div class="admin-toolbar">
                    <div>
                        <div class="admin-toolbar__title">{{ __('support.index.new_request') }}</div>
                        <p class="admin-toolbar__subtitle">{{ __('support.index.new_request_help') }}</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('support.store') }}" class="mt-6 space-y-6" x-data="{ type: @js(old('type', $canSubmitProblem ? 'problem' : 'suggestion')) }">
                    @csrf

                    @if ($canSubmitProblem && $canSubmitSuggestion)
                        <fieldset>
                            <legend class="mb-3 text-sm font-medium text-white">{{ __('support.index.request_type') }}</legend>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="cursor-pointer rounded-2xl border p-4 transition" :class="type === 'problem' ? 'border-emerald-400/50 bg-emerald-400/10' : 'border-white/10 bg-white/[0.03] hover:border-white/20'">
                                    <input type="radio" name="type" value="problem" x-model="type" class="sr-only">
                                    <span class="flex items-center gap-3">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-400/10 text-amber-200">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 8v4m0 4h.01M10.3 3.8 2.6 17.1A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.9L13.7 3.8a2 2 0 0 0-3.4 0Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        </span>
                                        <span class="font-medium text-white">{{ __('support.index.report_problem') }}</span>
                                    </span>
                                </label>
                                <label class="cursor-pointer rounded-2xl border p-4 transition" :class="type === 'suggestion' ? 'border-emerald-400/50 bg-emerald-400/10' : 'border-white/10 bg-white/[0.03] hover:border-white/20'">
                                    <input type="radio" name="type" value="suggestion" x-model="type" class="sr-only">
                                    <span class="flex items-center gap-3">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-400/10 text-emerald-200">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M9 18h6m-5 3h4m3.5-8.5c.9-1 1.5-2.3 1.5-3.8a7 7 0 1 0-14 0c0 1.5.6 2.8 1.5 3.8.8.9 1.5 1.8 1.5 3h8c0-1.2.7-2.1 1.5-3Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        </span>
                                        <span class="font-medium text-white">{{ __('support.index.suggest_improvement') }}</span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>
                    @else
                        <input type="hidden" name="type" x-model="type">
                        <div>
                            <p class="mb-3 text-sm font-medium text-white">{{ __('support.index.request_type') }}</p>
                            <div class="rounded-2xl border border-emerald-400/40 bg-emerald-400/10 p-4">
                                <span class="flex items-center gap-3">
                                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/5 text-emerald-200">
                                        @if ($canSubmitProblem)
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 8v4m0 4h.01M10.3 3.8 2.6 17.1A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.9L13.7 3.8a2 2 0 0 0-3.4 0Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        @else
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M9 18h6m-5 3h4m3.5-8.5c.9-1 1.5-2.3 1.5-3.8a7 7 0 1 0-14 0c0 1.5.6 2.8 1.5 3.8.8.9 1.5 1.8 1.5 3h8c0-1.2.7-2.1 1.5-3Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        @endif
                                    </span>
                                    <span class="font-medium text-white">{{ $canSubmitProblem ? __('support.index.report_problem') : __('support.index.suggest_improvement') }}</span>
                                </span>
                            </div>
                        </div>
                    @endif

                    <div class="grid gap-5 lg:grid-cols-2">
                        <label class="block lg:col-span-2">
                            <span class="mb-2 block text-sm font-medium text-white">{{ __('support.index.request_subject') }}</span>
                            <input name="subject" value="{{ old('subject') }}" required maxlength="255" placeholder="{{ __('support.index.short_subject') }}" class="w-full rounded-xl border border-white/10 bg-black/20 px-4 py-3 text-white placeholder:text-neutral-500 focus:border-emerald-400/60 focus:outline-none focus:ring-2 focus:ring-emerald-400/15">
                        </label>

                        <div class="contents" x-show="type === 'problem'" x-cloak>
                            <label class="block">
                                <span class="mb-2 block text-sm font-medium text-white">{{ __('support.index.reason') }}</span>
                                <select name="problem_reason" x-bind:disabled="type !== 'problem'" x-bind:required="type === 'problem'" class="w-full rounded-xl border border-white/10 bg-black/20 px-4 py-3 text-white focus:border-emerald-400/60 focus:outline-none focus:ring-2 focus:ring-emerald-400/15">
                                    <option value="">{{ __('support.index.reason') }}</option>
                                    @foreach ($problemReasonOptions as $value => $label)
                                        <option value="{{ $value }}" @selected(old('problem_reason') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-2 block text-sm font-medium text-white">{{ __('support.index.problem_priority', ['priority' => '']) }}</span>
                                <select name="priority" x-bind:disabled="type !== 'problem'" x-bind:required="type === 'problem'" class="w-full rounded-xl border border-white/10 bg-black/20 px-4 py-3 text-white focus:border-emerald-400/60 focus:outline-none focus:ring-2 focus:ring-emerald-400/15">
                                    @foreach ($priorityOptions as $priority => $label)
                                        <option value="{{ $priority }}" @selected(old('priority', 'normal') === $priority)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block lg:col-span-2">
                                <span class="mb-2 block text-sm font-medium text-white">{{ __('support.index.who_is_affected') }}</span>
                                <select name="impact" x-bind:disabled="type !== 'problem'" x-bind:required="type === 'problem'" class="w-full rounded-xl border border-white/10 bg-black/20 px-4 py-3 text-white focus:border-emerald-400/60 focus:outline-none focus:ring-2 focus:ring-emerald-400/15">
                                    <option value="">{{ __('support.index.who_is_affected') }}</option>
                                    @foreach ($impactOptions as $value => $label)
                                        <option value="{{ $value }}" @selected(old('impact') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <label class="block lg:col-span-2">
                            <span class="mb-2 block text-sm font-medium text-white">{{ __('support.index.request_message') }}</span>
                            <textarea name="message" required rows="5" placeholder="{{ __('support.index.message') }}" class="w-full rounded-xl border border-white/10 bg-black/20 px-4 py-3 text-white placeholder:text-neutral-500 focus:border-emerald-400/60 focus:outline-none focus:ring-2 focus:ring-emerald-400/15">{{ old('message') }}</textarea>
                        </label>

                        <label class="block lg:col-span-2" x-show="type === 'problem'" x-cloak>
                            <span class="mb-2 block text-sm font-medium text-white">{{ __('support.common.expected') }}</span>
                            <textarea name="expected_result" x-bind:disabled="type !== 'problem'" x-bind:required="type === 'problem'" rows="3" placeholder="{{ __('support.index.expected_result') }}" class="w-full rounded-xl border border-white/10 bg-black/20 px-4 py-3 text-white placeholder:text-neutral-500 focus:border-emerald-400/60 focus:outline-none focus:ring-2 focus:ring-emerald-400/15">{{ old('expected_result') }}</textarea>
                        </label>

                        <div class="contents" x-show="type === 'suggestion'" x-cloak>
                            <label class="block lg:col-span-2">
                                <span class="mb-2 block text-sm font-medium text-white">{{ __('support.common.desired_outcome') }}</span>
                                <textarea name="desired_outcome" x-bind:disabled="type !== 'suggestion'" x-bind:required="type === 'suggestion'" rows="3" placeholder="{{ __('support.index.desired_outcome') }}" class="w-full rounded-xl border border-white/10 bg-black/20 px-4 py-3 text-white placeholder:text-neutral-500 focus:border-emerald-400/60 focus:outline-none focus:ring-2 focus:ring-emerald-400/15">{{ old('desired_outcome') }}</textarea>
                            </label>
                            <label class="block">
                                <span class="mb-2 block text-sm font-medium text-white">{{ __('support.common.current_workaround') }}</span>
                                <textarea name="current_workaround" x-bind:disabled="type !== 'suggestion'" rows="3" placeholder="{{ __('support.index.current_workaround') }}" class="w-full rounded-xl border border-white/10 bg-black/20 px-4 py-3 text-white placeholder:text-neutral-500 focus:border-emerald-400/60 focus:outline-none focus:ring-2 focus:ring-emerald-400/15">{{ old('current_workaround') }}</textarea>
                            </label>
                            <div class="space-y-5">
                                <label class="block">
                                    <span class="mb-2 block text-sm font-medium text-white">{{ __('support.common.affected_users') }}</span>
                                    <input name="affected_users" value="{{ old('affected_users') }}" x-bind:disabled="type !== 'suggestion'" x-bind:required="type === 'suggestion'" placeholder="{{ __('support.index.affected_users') }}" class="w-full rounded-xl border border-white/10 bg-black/20 px-4 py-3 text-white placeholder:text-neutral-500 focus:border-emerald-400/60 focus:outline-none focus:ring-2 focus:ring-emerald-400/15">
                                </label>
                                <label class="block">
                                    <span class="mb-2 block text-sm font-medium text-white">{{ __('support.index.business_impact') }}</span>
                                    <select name="business_impact" x-bind:disabled="type !== 'suggestion'" x-bind:required="type === 'suggestion'" class="w-full rounded-xl border border-white/10 bg-black/20 px-4 py-3 text-white focus:border-emerald-400/60 focus:outline-none focus:ring-2 focus:ring-emerald-400/15">
                                        <option value="">{{ __('support.index.business_impact') }}</option>
                                        @foreach (\App\Models\TenantSupportRequest::businessImpactOptions() as $value => $label)
                                            <option value="{{ $value }}" @selected(old('business_impact') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-white/10 pt-5">
                        <button type="submit" class="pill-link pill-link--accent min-w-40 justify-center">{{ __('support.index.submit') }}</button>
                    </div>
                </form>
            </section>
        @endif

        <section class="surface-panel settings-dark-surface p-5 lg:p-6" data-support-history>
            <div class="admin-toolbar">
                <div>
                    <div class="admin-toolbar__title">{{ __('support.index.my_requests') }}</div>
                    <p class="admin-toolbar__subtitle">{{ __('support.index.request_history_help') }}</p>
                </div>
                <span class="status-chip status-chip--slate">{{ $requests->count() }}</span>
            </div>

            <div class="mt-6 space-y-4">
                @forelse ($requests as $item)
                    @php
                        $statusTone = match ($item->status) {
                            'resolved', 'released', 'implemented_internally' => 'emerald',
                            'closed', 'declined' => 'slate',
                            'forwarded', 'in_progress', 'planned' => 'blue',
                            default => 'amber',
                        };
                    @endphp
                    <details class="group overflow-hidden rounded-2xl border border-white/10 bg-white/[0.025]" @if($loop->first) open @endif data-support-request="{{ $item->id }}">
                        <summary class="flex cursor-pointer list-none flex-col gap-4 p-4 marker:hidden sm:flex-row sm:items-center sm:justify-between lg:p-5">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="status-chip status-chip--{{ $statusTone }}">{{ \App\Models\TenantSupportRequest::statusLabel($item->status) }}</span>
                                    <span class="status-chip status-chip--slate">{{ \App\Models\TenantSupportRequest::typeLabel($item->type) }}</span>
                                    @if ($item->incident_reference)
                                        <span class="font-mono text-xs text-neutral-400">{{ $item->incident_reference }}</span>
                                    @endif
                                </div>
                                <h2 class="mt-3 truncate text-base font-semibold text-white">{{ $item->subject }}</h2>
                                <p class="mt-1 text-xs text-neutral-400">{{ __('support.common.submitted_at', ['date' => $item->created_at->format('Y-m-d H:i')]) }}</p>
                            </div>
                            <span class="flex shrink-0 items-center gap-2 text-sm text-emerald-200">
                                {{ __('support.index.view_request') }}
                                <svg class="h-4 w-4 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </span>
                        </summary>

                        <div class="border-t border-white/10 p-4 lg:p-5">
                            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(18rem,.65fr)]">
                                <div class="space-y-5">
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
                                                @if ($item->decline_reason)<div class="rounded-xl border border-amber-300/20 bg-amber-300/5 p-3 text-sm text-neutral-200 sm:col-span-2"><strong class="text-white">{{ __('support.index.tenant_response') }}</strong> {{ $item->decline_reason }}</div>@endif
                                            @endif
                                        </div>
                                    </section>

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
                                                <p class="rounded-xl border border-dashed border-white/10 p-4 text-sm text-neutral-500">{{ __('support.index.no_replies') }}</p>
                                            @endforelse
                                        </div>
                                    </section>
                                </div>

                                <aside class="space-y-5">
                                    @if ($item->type === \App\Models\TenantSupportRequest::TYPE_PROBLEM)
                                        <section class="rounded-2xl border border-white/10 bg-black/10 p-4">
                                            <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-neutral-400">{{ __('support.common.attachments') }}</h3>
                                            <div class="mt-3 space-y-2 text-sm">
                                                @forelse ($item->attachments as $attachment)
                                                    <a href="{{ route('support.attachments.download', $attachment) }}" class="flex items-center gap-2 rounded-xl border border-white/10 px-3 py-2 text-emerald-200 transition hover:bg-white/5">
                                                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m21.4 11.6-8.9 8.9a6 6 0 0 1-8.5-8.5l9.6-9.6a4 4 0 0 1 5.7 5.7l-9.6 9.6a2 2 0 0 1-2.8-2.8l8.9-8.9" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                                        <span class="truncate">{{ $attachment->original_name }}</span>
                                                    </a>
                                                @empty
                                                    <p class="text-neutral-500">{{ __('support.common.no_attachments') }}</p>
                                                @endforelse
                                            </div>
                                        </section>
                                    @endif

                                    @if (! $item->isClosed())
                                        <section class="rounded-2xl border border-white/10 bg-white/[0.025] p-4">
                                            <form method="POST" action="{{ route('support.messages.store', $item) }}" class="space-y-3">
                                                @csrf
                                                <label class="block">
                                                    <span class="sr-only">{{ __('support.index.reply_placeholder') }}</span>
                                                    <textarea name="message" required maxlength="5000" rows="3" placeholder="{{ __('support.index.reply_placeholder') }}" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-3 text-sm text-white placeholder:text-neutral-500 focus:border-emerald-400/60 focus:outline-none"></textarea>
                                                </label>
                                                <button class="pill-link pill-link--accent w-full justify-center">{{ __('support.common.send') }}</button>
                                            </form>

                                            @if ($item->type === \App\Models\TenantSupportRequest::TYPE_PROBLEM)
                                                <form method="POST" action="{{ route('support.attachments.store', $item) }}" enctype="multipart/form-data" class="mt-4 space-y-3 border-t border-white/10 pt-4">
                                                    @csrf
                                                    <input type="file" name="attachment" required accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" class="block w-full text-xs text-neutral-400 file:me-3 file:rounded-full file:border-0 file:bg-emerald-400/10 file:px-3 file:py-2 file:text-emerald-200">
                                                    <button class="pill-link w-full justify-center">{{ __('support.common.attach_file') }}</button>
                                                    <p class="text-xs leading-5 text-neutral-500">{{ __('support.common.attachments_help') }}</p>
                                                </form>
                                            @endif
                                        </section>
                                    @endif
                                </aside>
                            </div>
                        </div>
                    </details>
                @empty
                    <div class="rounded-2xl border border-dashed border-white/10 px-5 py-12 text-center">
                        <p class="font-medium text-white">{{ __('support.common.no_requests') }}</p>
                        <p class="mt-2 text-sm text-neutral-500">{{ __('support.index.no_requests_help') }}</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.app>
