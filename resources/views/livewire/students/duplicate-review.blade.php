<x-admin.modal :show="$reviewedDuplicate !== null" :title="__('duplicates.title')" :dismissible="false" max-width="3xl">
    @if ($profile = $this->duplicateReviewProfile)
        @php
            $isStudent = $reviewedDuplicate['type'] === 'student';
            $fields = $isStudent ? [
                __('crud.students.form.fields.student_number') => $profile->student_number,
                __('crud.students.form.fields.first_name') => $profile->first_name,
                __('crud.students.form.fields.last_name') => $profile->last_name,
                __('crud.students.form.fields.birth_year') => $profile->birth_date?->format('Y'),
                __('crud.students.form.fields.phone') => $profile->user?->phone,
                __('crud.students.form.fields.parent') => $profile->parentProfile?->father_name,
                __('crud.students.form.fields.grade_level') => $profile->gradeLevel?->name,
                __('crud.students.form.fields.school') => $profile->school_name,
            ] : [
                __('crud.parents.form.fields.father_name') => $profile->father_name,
                __('crud.parents.form.fields.father_phone') => $profile->father_phone,
                __('crud.parents.form.fields.father_work') => $profile->father_work,
                __('crud.parents.form.fields.mother_name') => $profile->mother_name,
                __('crud.parents.form.fields.mother_phone') => $profile->mother_phone,
                __('crud.parents.form.fields.home_phone') => $profile->home_phone,
                __('crud.parents.form.fields.address') => $profile->address,
                __('duplicates.children') => $profile->students->map(fn ($child) => trim($child->first_name.' '.$child->last_name))->implode('، '),
            ];
        @endphp
        <div class="grid gap-3 sm:grid-cols-2" data-profile-duplicate-review>
            @foreach ($fields as $label => $value)
                <div class="min-w-0 rounded-2xl border border-white/8 bg-white/4 p-3">
                    <div class="kpi-label">{{ $label }}</div>
                    <div class="mt-2 break-words text-sm font-semibold text-white">
                        @if ($value && in_array($label, [__('crud.students.form.fields.phone'), __('crud.parents.form.fields.father_phone'), __('crud.parents.form.fields.mother_phone'), __('crud.parents.form.fields.home_phone')], true))
                            <bdi dir="ltr" class="inline-block whitespace-nowrap">{{ \App\Support\PhoneNumberFormatter::format($value) }}</bdi>
                        @else
                            {{ $value ?: __('crud.common.not_available') }}
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        @if ($reviewedDuplicate['reason'] === 'phone')
            <p class="mt-4 text-sm text-red-300">{{ __('duplicates.phone_note') }}</p>
        @endif
        <div class="duplicate-review-actions mt-5">
            <button type="button" wire:click="continueDuplicateDraft" data-modal-action-icon-ignore class="pill-link">{{ __('duplicates.keep') }}</button>
            <button type="button" wire:click="useReviewedDuplicate" data-modal-action-icon-ignore wire:loading.attr="disabled" class="pill-link pill-link--accent">{{ __('duplicates.'.($isStudent ? 'use_student' : 'use_parent')) }}</button>
        </div>
    @endif
</x-admin.modal>
