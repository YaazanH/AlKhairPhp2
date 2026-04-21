<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\Student;
use App\Services\AccessScopeService;
use App\Services\ActivityAudienceService;
use App\Services\FinanceService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public array $editingResponses = [];

    public function mount(): void
    {
        $this->authorizePermission('activities.responses.view');
    }

    public function with(): array
    {
        $user = auth()->user();
        $scope = app(AccessScopeService::class);
        $audience = app(ActivityAudienceService::class);

        $students = $scope->scopeStudents(
            Student::query()->with(['gradeLevel', 'enrollments.group'])->where('status', 'active'),
            $user,
        )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $studentIds = $students->pluck('id');

        $activities = $scope->scopeActivities(
            Activity::query()->with(['group.course', 'targetGroups.course'])->where('is_active', true),
            $user,
        )
            ->orderBy('activity_date')
            ->orderBy('title')
            ->get();

        $registrations = ActivityRegistration::query()
            ->with(['payments' => fn ($query) => $query->whereNull('voided_at')])
            ->whereIn('activity_id', $activities->pluck('id'))
            ->when($studentIds->isNotEmpty(), fn ($query) => $query->whereIn('student_id', $studentIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->get()
            ->keyBy(fn (ActivityRegistration $registration) => $registration->activity_id.'-'.$registration->student_id);

        $activityCards = $activities
            ->map(function (Activity $activity) use ($students, $registrations, $audience) {
                $eligibleStudents = $students
                    ->map(function (Student $student) use ($activity, $registrations, $audience) {
                        $enrollment = $audience->resolveEnrollmentForStudent($activity, $student);

                        if (! $enrollment) {
                            return null;
                        }

                        $registration = $registrations->get($activity->id.'-'.$student->id);

                        return [
                            'enrollment' => $enrollment,
                            'registration' => $registration,
                            'student' => $student,
                        ];
                    })
                    ->filter()
                    ->values();

                return [
                    'activity' => $activity,
                    'eligible_students' => $eligibleStudents,
                ];
            })
            ->filter(fn (array $card) => $card['eligible_students']->isNotEmpty())
            ->values();

        return [
            'activityCards' => $activityCards,
            'responseCount' => $activityCards->sum(fn (array $card) => $card['eligible_students']->count()),
            'studentCount' => $students->count(),
        ];
    }

    public function respond(int $activityId, int $studentId, string $response): void
    {
        $this->authorizePermission('activities.responses.respond');

        if (! in_array($response, ['registered', 'declined'], true)) {
            abort(404);
        }

        $user = auth()->user();
        $scope = app(AccessScopeService::class);
        $audience = app(ActivityAudienceService::class);

        $activity = $scope->scopeActivities(
            Activity::query()->with('targetGroups'),
            $user,
        )->findOrFail($activityId);

        $student = $scope->scopeStudents(
            Student::query()->with('enrollments.group'),
            $user,
        )->findOrFail($studentId);

        $enrollment = $audience->resolveEnrollmentForStudent($activity, $student);

        if (! $enrollment) {
            $this->addError('response', __('activities.family.errors.not_eligible'));

            return;
        }

        $registration = ActivityRegistration::query()->firstOrNew([
            'activity_id' => $activity->id,
            'student_id' => $student->id,
        ]);

        if ($response === 'declined' && $registration->exists && $registration->payments()->whereNull('voided_at')->exists()) {
            $this->addError('response', __('activities.family.errors.locked_after_payment'));

            return;
        }

        $registration->fill([
            'enrollment_id' => $enrollment->id,
            'fee_amount' => $registration->exists ? $registration->fee_amount : ($activity->fee_amount ?? 0),
            'status' => $response,
            'notes' => $registration->notes,
        ]);
        $registration->save();

        app(FinanceService::class)->syncActivityTotals($activity->fresh());

        session()->flash('status', __('activities.family.messages.'.$response));
        $this->resetErrorBag('response');
        unset($this->editingResponses[$activityId.'-'.$studentId]);
    }

    public function editResponse(int $activityId, int $studentId): void
    {
        $this->authorizePermission('activities.responses.respond');

        $this->editingResponses[$activityId.'-'.$studentId] = true;
    }

    public function cancelEditResponse(int $activityId, int $studentId): void
    {
        unset($this->editingResponses[$activityId.'-'.$studentId]);
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('activities.family.heading') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('activities.family.subheading') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @error('response')
        <div class="rounded-2xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-200">
            {{ $message }}
        </div>
    @enderror

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('activities.family.stats.activities') }}</div>
            <div class="metric-value mt-3">{{ number_format($activityCards->count()) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('activities.family.stats.students') }}</div>
            <div class="metric-value mt-3">{{ number_format($studentCount) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('activities.family.stats.responses') }}</div>
            <div class="metric-value mt-3">{{ number_format($responseCount) }}</div>
        </article>
    </section>

    @if ($activityCards->isEmpty())
        <section class="surface-panel p-6 lg:p-8">
            <div class="admin-empty-state">
                <h2 class="text-lg font-semibold text-white">{{ __('activities.family.empty.title') }}</h2>
                <p class="mt-2 text-sm text-neutral-400">{{ __('activities.family.empty.body') }}</p>
            </div>
        </section>
    @else
        <div class="space-y-6">
            @foreach ($activityCards as $card)
                @php
                    $activity = $card['activity'];
                    $audienceText = match ($activity->audience_scope) {
                        'single_group' => $activity->group?->name ?: ($activity->targetGroups->first()?->name ?: __('activities.common.audience.unassigned')),
                        'multiple_groups' => $activity->targetGroups->pluck('name')->implode(', '),
                        default => __('activities.common.audience.all_groups'),
                    };
                @endphp

                <section class="surface-panel p-5 lg:p-6">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-3">
                                <h2 class="text-2xl font-semibold text-white">{{ $activity->title }}</h2>
                                <span class="status-chip status-chip--emerald">{{ __('activities.common.states.'.($activity->is_active ? 'active' : 'inactive')) }}</span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-4 text-sm text-neutral-300">
                                <span>{{ __('activities.family.meta.date') }}: {{ $activity->activity_date?->format('Y-m-d') }}</span>
                                <span>{{ __('activities.family.meta.fee') }}: {{ number_format((float) ($activity->fee_amount ?? 0), 2) }}</span>
                                <span>{{ __('activities.family.meta.audience') }}: {{ $audienceText }}</span>
                            </div>
                            @if ($activity->description)
                                <p class="mt-4 max-w-4xl text-sm leading-7 text-neutral-300">{{ $activity->description }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-6 overflow-x-auto">
                        <table class="text-sm">
                            <thead>
                                <tr>
                                    <th class="px-5 py-3 text-left font-medium">{{ __('activities.family.table.headers.student') }}</th>
                                    <th class="px-5 py-3 text-left font-medium">{{ __('activities.family.table.headers.group') }}</th>
                                    <th class="px-5 py-3 text-left font-medium">{{ __('activities.family.table.headers.response') }}</th>
                                    <th class="px-5 py-3 text-right font-medium">{{ __('activities.family.table.headers.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/6">
                                @foreach ($card['eligible_students'] as $entry)
                                    @php
                                        $registration = $entry['registration'];
                                        $responseState = $registration?->status ?: 'pending';
                                        $hasPayment = $registration?->payments?->isNotEmpty() ?? false;
                                        $responseKey = $activity->id.'-'.$entry['student']->id;
                                        $isEditingResponse = $this->editingResponses[$responseKey] ?? false;
                                    @endphp
                                    <tr>
                                        <td class="px-5 py-4">
                                            <div class="student-inline">
                                                <x-student-avatar :student="$entry['student']" size="sm" />
                                                <div class="student-inline__body">
                                                    <div class="student-inline__name">{{ $entry['student']->full_name }}</div>
                                                    <div class="text-xs text-neutral-500">{{ $entry['student']->gradeLevel?->name ?: __('dashboard.common.no_grade') }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-5 py-4">{{ $entry['enrollment']->group?->name ?: '-' }}</td>
                                        <td class="px-5 py-4">
                                            <span class="status-chip {{ $responseState === 'registered' ? 'status-chip--emerald' : ($responseState === 'declined' ? 'status-chip--rose' : 'status-chip--slate') }}">
                                                {{ __('activities.common.states.'.$responseState) }}
                                            </span>
                                        </td>
                                        <td class="px-5 py-4">
                                            <div class="admin-action-cluster admin-action-cluster--end">
                                                @can('activities.responses.respond')
                                                    @if ($registration && ! $isEditingResponse)
                                                        <button type="button" wire:click="editResponse({{ $activity->id }}, {{ $entry['student']->id }})" @disabled($hasPayment) class="pill-link pill-link--compact disabled:cursor-not-allowed disabled:opacity-60">
                                                            {{ __('activities.family.actions.edit_response') }}
                                                        </button>
                                                    @else
                                                        <button type="button" wire:click="respond({{ $activity->id }}, {{ $entry['student']->id }}, 'registered')" class="pill-link pill-link--compact pill-link--accent">
                                                            {{ __('activities.family.actions.attend') }}
                                                        </button>
                                                        <button type="button" wire:click="respond({{ $activity->id }}, {{ $entry['student']->id }}, 'declined')" @disabled($hasPayment) class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12 disabled:cursor-not-allowed disabled:opacity-60">
                                                            {{ __('activities.family.actions.decline') }}
                                                        </button>
                                                        @if ($registration)
                                                            <button type="button" wire:click="cancelEditResponse({{ $activity->id }}, {{ $entry['student']->id }})" class="pill-link pill-link--compact">
                                                                {{ __('activities.family.actions.cancel_edit') }}
                                                            </button>
                                                        @endif
                                                    @endif
                                                @endcan
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
